<?php

namespace App\Previews;

use App\Actions\AttachDatastore;
use App\Actions\StartDeployment;
use App\Enums\ApplicationType;
use App\Enums\DatastoreSource;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\GitHub\GitHubClient;
use App\GitHub\PullRequestAction;
use App\GitHub\PullRequestEvent;
use App\Jobs\CheckDomain;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes sure the preview of a pull request exists and deploys its head commit.
 */
class OpenPreview
{
    /** The settings that a preview takes from the preview template, next to its processes. Web always runs one instance. */
    private const TEMPLATE_SETTINGS = ['size', 'cpu_target', 'build_command', 'deploy_commands', 'health_path'];

    public function __construct(
        private AttachDatastore $attachDatastore,
        private StartDeployment $startDeployment,
        private GitHubClient $github,
    ) {}

    /** @return Environment|null null when the application gets no preview for this pull request */
    public function handle(Application $application, PullRequestEvent $pullRequest): ?Environment
    {
        $skipped = match (true) {
            ! $application->previews_enabled => 'previews_disabled',
            $pullRequest->fromFork && ! $application->allow_forks => 'fork',
            default => null,
        };

        if ($skipped !== null) {
            AuditEvent::record(null, 'preview.skipped', $application, ['pr_number' => $pullRequest->number, 'reason' => $skipped]);

            return null;
        }

        $preview = $application->environments()->where('kind', EnvironmentKind::Preview)->where('pr_number', $pullRequest->number)->first()
            ?? $this->create($application, $pullRequest);

        if ($preview !== null) {
            $this->startDeployment->handle($preview, null, $pullRequest->headSha);
        }

        return $preview;
    }

    private function create(Application $application, PullRequestEvent $pullRequest): ?Environment
    {
        $limit = config('opencloud.max_previews');

        if ($application->environments()->where('kind', EnvironmentKind::Preview)->count() >= $limit) {
            AuditEvent::record(null, 'preview.limit_reached', $application, ['pr_number' => $pullRequest->number, 'limit' => $limit]);

            // A push to the pull request tries again, so only a new or reopened pull request gets the comment.
            if ($pullRequest->action !== PullRequestAction::Synchronize) {
                $this->comment($pullRequest, "Opencloud did not create a preview for this pull request. {$application->name} already runs {$limit} previews, which is the limit. Close a pull request that has a preview, then push to this pull request to try again.");
            }

            return null;
        }

        $template = $application->environments()->where('kind', EnvironmentKind::PreviewTemplate)->firstOrFail();
        $production = $application->environments()->where('name', 'production')->first();
        // The default comes first, so it is the default of the preview too. A database that DigitalOcean destroys has no name.
        $attachments = $production?->datastoreAttachments()->with('datastore')->orderByDesc('is_default')->orderBy('id')->get()
            ->reject(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind->isSql() && $attachment->name === null) ?? collect();

        return DB::transaction(function () use ($application, $pullRequest, $template, $attachments) {
            $preview = $application->environments()->create([
                ...$template->only(self::TEMPLATE_SETTINGS),
                'name' => "pr-{$pullRequest->number}",
                'kind' => EnvironmentKind::Preview,
                'pr_number' => $pullRequest->number,
                'branch' => $pullRequest->headRef,
                'is_protected' => false,
                'min_instances' => 1,
                'max_instances' => 1,
            ]);

            foreach ($template->processes as $process) {
                $preview->processes()->create($process->only(['name', 'command', 'instances', 'exclusive']));
            }

            AuditEvent::record(null, 'preview.created', $preview, [
                'pr_number' => $pullRequest->number,
                'branch' => $pullRequest->headRef,
                'from_fork' => $pullRequest->fromFork,
                'processes' => $template->processes->pluck('name')->all(),
            ]);

            $this->copyEnv($application, $template, $preview);

            foreach ($attachments as $attachment) {
                // A preview never creates a paid cloud resource, so a managed database of production becomes a local one.
                $this->attachDatastore->handle($preview, null, $attachment->datastore->kind, DatastoreSource::Standard, $attachment->name);
            }

            $hostname = $this->hostname($application, $pullRequest->number);

            if ($hostname !== null) {
                $domain = $preview->domains()->create(['hostname' => $hostname]);
                AuditEvent::record(null, 'domain.added', $domain, ['environment_id' => $preview->id, 'hostname' => $hostname]);
                CheckDomain::dispatch($domain)->afterCommit();
            }

            return $preview;
        });
    }

    /**
     * Copies each user value of the template, secrets too, as values of the preview. A Laravel preview without
     * an APP_KEY gets its own, because production keeps its key to itself.
     */
    private function copyEnv(Application $application, Environment $template, Environment $preview): void
    {
        $copied = $template->envVars()->where('source', EnvVarSource::User)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => $preview->envVars()->create($var->only(['key', 'value', 'is_secret', 'available_at', 'source']))->key);
        $generated = [];

        if ($application->type === ApplicationType::Laravel && ! $copied->contains('APP_KEY')) {
            $preview->envVars()->create(['key' => 'APP_KEY', 'value' => 'base64:'.base64_encode(random_bytes(32)), 'is_secret' => true, 'available_at' => EnvVarAvailability::Runtime]);
            $generated[] = 'APP_KEY';
        }

        AuditEvent::record(null, 'preview.env_copied', $preview, ['keys' => $copied->all(), 'generated' => $generated]);
    }

    /** With a preview domain the owner points one wildcard record at the cluster. Without one, sslip.io resolves the IP in the name. */
    private function hostname(Application $application, int $number): ?string
    {
        if ($application->preview_domain !== null) {
            return "pr-{$number}.{$application->preview_domain}";
        }

        $ip = $application->cluster->ingress_ip;

        // A DNS label holds at most 63 characters.
        $slug = rtrim(substr(Str::slug($application->name), 0, 50), '-');

        return $ip === null ? null : "pr-{$number}-{$slug}.{$ip}.sslip.io";
    }

    /** A comment is a courtesy, so a failure only goes to the log. */
    private function comment(PullRequestEvent $pullRequest, string $body): void
    {
        try {
            $this->github->comment($pullRequest->repository, $pullRequest->number, $body);
        } catch (Throwable $e) {
            Log::warning("Opencloud could not comment on {$pullRequest->repository}#{$pullRequest->number}.", ['error' => $e->getMessage()]);
        }
    }
}

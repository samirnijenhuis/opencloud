<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\GitHub\Enums\PullRequestAction;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\PullRequestEvent;
use App\Domains\Routing\Jobs\CheckDomain;
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
    public function execute(Application $application, PullRequestEvent $pullRequest): ?Environment
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

        $preview = $application->environments()->where('kind', EnvironmentKind::PREVIEW)->where('pr_number', $pullRequest->number)->first()
            ?? $this->create($application, $pullRequest);

        if ($preview !== null) {
            $this->startDeployment->execute($preview, null, $pullRequest->headSha);
        }

        return $preview;
    }

    private function create(Application $application, PullRequestEvent $pullRequest): ?Environment
    {
        $limit = config('opencloud.max_previews');

        if ($application->environments()->where('kind', EnvironmentKind::PREVIEW)->count() >= $limit) {
            AuditEvent::record(null, 'preview.limit_reached', $application, ['pr_number' => $pullRequest->number, 'limit' => $limit]);

            // A push to the pull request tries again, so only a new or reopened pull request gets the comment.
            if ($pullRequest->action !== PullRequestAction::SYNCHRONIZE) {
                $this->comment($pullRequest, "Opencloud did not create a preview for this pull request. {$application->name} already runs {$limit} previews, which is the limit. Close a pull request that has a preview, then push to this pull request to try again.");
            }

            return null;
        }

        $template = $application->environments()->where('kind', EnvironmentKind::PREVIEW_TEMPLATE)->firstOrFail();
        $production = $application->environments()->where('name', 'production')->first();
        // The default comes first, so it is the default of the preview too. A database that DigitalOcean destroys has no name.
        $attachments = $production?->datastoreAttachments()->with('datastore')->orderByDesc('is_default')->orderBy('id')->get()
            ->reject(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind->family()->isNamed() && $attachment->name === null) ?? collect();

        return DB::transaction(function () use ($application, $pullRequest, $template, $attachments) {
            $preview = $application->environments()->create([
                ...$template->only(self::TEMPLATE_SETTINGS),
                'name' => "pr-{$pullRequest->number}",
                'kind' => EnvironmentKind::PREVIEW,
                'pr_number' => $pullRequest->number,
                'branch' => $pullRequest->headRef,
                'is_protected' => false,
                'min_instances' => 1,
                'max_instances' => 1,
            ]);

            foreach ($template->processes as $process) {
                $preview->processes()->create($process->only(['name', 'kind', 'command', 'schedule', 'size', 'min_instances', 'max_instances', 'cpu_target']));
            }

            AuditEvent::record(null, 'preview.created', $preview, [
                'pr_number' => $pullRequest->number,
                'branch' => $pullRequest->headRef,
                'from_fork' => $pullRequest->fromFork,
                'processes' => $template->processes->pluck('name')->all(),
            ]);

            $hostname = $this->hostname($application, $pullRequest->number);

            $this->copyEnv($application, $template, $preview, [
                '{pr_number}' => (string) $pullRequest->number,
                '{branch}' => $pullRequest->headRef,
                '{url}' => $hostname === null ? '' : "https://{$hostname}",
            ]);

            foreach ($attachments as $attachment) {
                // A preview never creates a paid cloud resource, so a managed database of production becomes a local one.
                $this->attachDatastore->execute($preview, null, $attachment->datastore->kind, DatastoreSource::STANDARD, $attachment->name);
            }

            if ($hostname !== null) {
                $domain = $preview->domains()->create(['hostname' => $hostname]);
                AuditEvent::record(null, 'domain.added', $domain, ['environment_id' => $preview->id, 'hostname' => $hostname]);
                CheckDomain::dispatch($domain)->afterCommit();
            }

            return $preview;
        });
    }

    /**
     * Copies each user value of the template, secrets too, as values of the preview, and fills in each placeholder.
     * The preset generates each secret that the template lacks.
     *
     * @param  array<string, string>  $placeholders
     */
    private function copyEnv(Application $application, Environment $template, Environment $preview, array $placeholders): void
    {
        $copied = $template->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => $preview->envVars()->create([
                ...$var->only(['key', 'is_secret', 'available_at', 'source']),
                'value' => strtr($var->value, $placeholders),
            ])->key);
        $generated = [];

        foreach ($application->type->generatedSecrets() as $key => $value) {
            if (! $copied->contains($key)) {
                $preview->envVars()->create(['key' => $key, 'value' => $value, 'is_secret' => true, 'available_at' => EnvVarAvailability::RUNTIME]);
                $generated[] = $key;
            }
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

<?php

namespace App\Domains\Restore;

use App\Domains\Backups\BackupDisk;
use App\Domains\Backups\DatabaseBackups;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Capabilities;
use App\Domains\Datastores\DatastoreManifests;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Deployments\DeploymentLog;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Restore\Enums\RestoreStep;
use App\Domains\Restore\Enums\StepStatus;
use App\Domains\Routing\CaddySync;
use App\Domains\Routing\CloudflareDns;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDashboardDomain;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\DashboardDomain;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\Kubectl;
use App\Support\SecretMasker;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Puts the local cluster of a restored dashboard back the way the lost server had it. A connected cluster kept
 * running, so its records need nothing. Each step converges when it runs again.
 */
final class RestoreInstall
{
    private const BUCKET_COPY_SECONDS = 3600;

    public function __construct(private StartDeployment $startDeployment, private CaddySync $caddy, private CloudflareDns $cloudflare) {}

    /** Runs each step that is not done, in order. A failed step stops the run, and the next run starts again at that step. */
    public function run(RestoreState $state): void
    {
        $cluster = DashboardDomain::localCluster() ?? throw new RuntimeException('The dashboard has no local cluster.');

        // The live deployments become superseded in the first step, so the plan is kept before it.
        if ($state->redeploy() === null) {
            $state->planRedeploy($this->liveCommits($cluster, $state->startedAt));
        }

        foreach (RestoreStep::cases() as $step) {
            if ($state->status($step) === StepStatus::DONE) {
                continue;
            }

            $state->mark($step, StepStatus::RUNNING);

            try {
                $detail = match ($step) {
                    RestoreStep::RETIRE_DEPLOYMENTS => $this->retireDeployments($cluster, $state->startedAt),
                    RestoreStep::PROVISION_DATASTORES => $this->provisionDatastores($cluster),
                    RestoreStep::RESTORE_DATABASES => $this->restoreDatabases($cluster, $state->startedAt),
                    RestoreStep::RESTORE_BUCKETS => $this->restoreBuckets($cluster),
                    RestoreStep::DEPLOY => $this->deploy($state->redeploy(), $state->startedAt),
                    RestoreStep::CHECK_DOMAINS => $this->checkDomains($cluster),
                    RestoreStep::SYNC_CADDY => $this->syncCaddy($cluster),
                };
            } catch (Throwable $e) {
                $state->mark($step, StepStatus::FAILED, $this->masker($cluster)->mask($e->getMessage()));

                return;
            }

            $state->mark($step, StepStatus::DONE, $detail);
        }
    }

    /**
     * The newest dump from before the restore began. A later dump can hold an empty database that a nightly backup
     * took on this server while the restore ran.
     */
    public static function newestDump(Filesystem $bucket, DatastoreAttachment $attachment, Carbon $before): ?string
    {
        $cutoff = $before->copy()->setTimezone(config('app.timezone'))->format(DatabaseBackups::TIME_FORMAT);

        return collect($bucket->files(DatabaseBackups::folder($attachment)))
            ->filter(fn (string $path) => str_ends_with($path, '.sql.gz') && basename($path, '.sql.gz') < $cutoff)
            ->sort()
            ->last();
    }

    /** @return array<int, string> environment id => commit SHA */
    private function liveCommits(Cluster $cluster, Carbon $before): array
    {
        return $this->oldDeployments($cluster, $before)
            ->filter(fn (Deployment $deployment) => $deployment->status === DeploymentStatus::LIVE && $deployment->environment->kind === EnvironmentKind::PERMANENT)
            ->mapWithKeys(fn (Deployment $deployment) => [$deployment->environment_id => $deployment->commit_sha])
            ->all();
    }

    private function retireDeployments(Cluster $cluster, Carbon $before): string
    {
        $deployments = $this->oldDeployments($cluster, $before);

        foreach ($deployments as $deployment) {
            $log = DeploymentLog::for($deployment);

            if ($deployment->status === DeploymentStatus::LIVE) {
                $log->write('Opencloud restored the install on a new server. This deployment does not run there.');
                $deployment->moveTo(DeploymentStatus::SUPERSEDED);

                continue;
            }

            $log->write('The server was lost during this deployment.');
            $deployment->finished_at = now();
            $deployment->moveTo(DeploymentStatus::FAILED);
        }

        $superseded = $deployments->filter(fn (Deployment $deployment) => $deployment->status === DeploymentStatus::SUPERSEDED)->count();

        return "{$superseded} superseded, ".($deployments->count() - $superseded).' failed.';
    }

    private function provisionDatastores(Cluster $cluster): string
    {
        $attachments = $this->attachments($cluster);

        foreach ($attachments as $attachment) {
            Provisioners::of($attachment->datastore)->provision($attachment);
        }

        $failed = $attachments->filter(fn (DatastoreAttachment $attachment) => $attachment->status === DatastoreStatus::FAILED);

        if ($failed->isNotEmpty()) {
            throw new RuntimeException($failed->map(fn (DatastoreAttachment $attachment) => "{$attachment->datastore->kind->value} {$attachment->database_name} failed. {$attachment->error}")->implode(' '));
        }

        return "{$attachments->count()} ready.";
    }

    private function restoreDatabases(Cluster $cluster, Carbon $before): string
    {
        $target = BackupDisk::target();

        if ($target === null) {
            return 'No backup target, so no dump to restore.';
        }

        $bucket = BackupDisk::s3($target);
        $lines = [];

        foreach ($this->attachments($cluster) as $attachment) {
            $dumps = Provisioners::of($attachment->datastore)->capabilities()->dumps;

            if ($dumps === null) {
                continue;
            }

            $dump = self::newestDump($bucket, $attachment, $before);

            if ($dump === null) {
                $lines[] = "{$attachment->database_name} has no dump, so it stays empty.";

                continue;
            }

            $stream = $bucket->readStream($dump);

            try {
                $dumps->restore($attachment, $stream);
            } finally {
                fclose($stream);
            }

            $lines[] = "{$attachment->database_name} from {$dump}.";
        }

        return $lines === [] ? 'No MySQL or Postgres database.' : implode(' ', $lines);
    }

    private function restoreBuckets(Cluster $cluster): string
    {
        $target = BackupDisk::target();
        $servers = Provisioners::datastores(fn (Capabilities $can) => $can->copiesBuckets)->where('cluster_id', $cluster->id)->orderBy('id')->get();

        if ($target === null || $servers->isEmpty()) {
            return $target === null ? 'No backup target, so no bucket copy.' : 'No RustFS server.';
        }

        $bucket = BackupDisk::s3($target);
        $kubectl = Kubectl::for($cluster);
        $lines = [];

        foreach ($servers as $server) {
            $folder = "rustfs/{$server->id}";

            if ($bucket->directories($folder) === []) {
                $lines[] = "RustFS {$server->id} has no copy in the backup target.";

                continue;
            }

            $this->copyBack($kubectl, $server, $target, $folder);
            $lines[] = "RustFS {$server->id} from {$folder}.";
        }

        return implode(' ', $lines);
    }

    /** The nightly copy Job with the direction turned around, so both use the same rclone settings. */
    private function copyBack(Kubectl $kubectl, Datastore $server, BackupTarget $target, string $folder): void
    {
        [$secret, $job] = DatastoreManifests::copyBuckets($server, $target, 'restore');
        $job['spec']['template']['spec']['containers'][0]['args'] = ['copy', "target:{$target->bucket}/{$folder}", 'source:'];
        $name = "job/{$job['metadata']['name']}";
        $namespace = '--namespace='.DatastoreManifests::NAMESPACE;

        // The pod template of a Job cannot change, so a Job from an earlier run goes first.
        $kubectl->delete('job', $job['metadata']['name'], DatastoreManifests::NAMESPACE, wait: true);
        $kubectl->apply([$secret, $job]);
        $deadline = now()->addSeconds(self::BUCKET_COPY_SECONDS);

        while (! $kubectl->run(['wait', '--for=condition=complete', $name, $namespace, '--timeout=10s'], timeout: 40)->ok()) {
            if ($kubectl->run(['wait', '--for=condition=failed', $name, $namespace, '--timeout=0s'], timeout: 40)->ok()) {
                throw new RuntimeException("The copy of RustFS {$server->id} failed. Read its log with: kubectl -n oc-datastores logs {$name}");
            }

            if (now()->greaterThan($deadline)) {
                throw new RuntimeException("The copy of RustFS {$server->id} did not finish in ".(self::BUCKET_COPY_SECONDS / 60).' minutes.');
            }
        }
    }

    /** @param  array<int, string>  $commits  environment id => commit SHA */
    private function deploy(array $commits, Carbon $before): string
    {
        $started = 0;

        foreach ($commits as $environmentId => $commitSha) {
            $environment = Environment::find($environmentId);

            // A deployment after the restore began comes from an earlier run of this step or from a person.
            if ($environment === null || $environment->deployments()->where('created_at', '>=', $before)->exists()) {
                continue;
            }

            $this->startDeployment->execute($environment, null, $commitSha);
            $started++;
        }

        return "Started {$started} of ".count($commits).' deployments.';
    }

    /**
     * The domains still point at the lost server. Each check starts again from pending, so Caddy serves a domain
     * only after its DNS points at this server.
     */
    private function checkDomains(Cluster $cluster): string
    {
        $domains = Domain::query()->whereHas('environment.application', fn (Builder $query) => $query->where('cluster_id', $cluster->id))->orderBy('id')->get();
        $credential = CloudflareDns::credential();
        $notes = [];

        foreach ($domains as $domain) {
            $zone = $domain->dns_mode === DnsMode::CLOUDFLARE ? CloudflareDns::zoneFor($credential, $domain->hostname) : null;

            if ($zone !== null && $cluster->ingress_ip !== null) {
                try {
                    $this->cloudflare->point($credential, $zone, $domain->hostname, $cluster->ingress_ip, $domain->dns_status === DnsStatus::PROXIED);
                    $notes[] = "Cloudflare points {$domain->hostname} at {$cluster->ingress_ip}.";
                } catch (ValidationException $e) {
                    $notes[] = collect($e->errors())->flatten()->implode(' ');
                }
            }

            $domain->update(['dns_status' => DnsStatus::PENDING]);
            CheckDomain::dispatch($domain);
        }

        $hostnames = $domains->pluck('hostname');
        $dashboardDomain = DashboardDomain::current();

        if ($dashboardDomain !== null) {
            $hostnames->push($dashboardDomain->hostname);
            $wasVerified = $dashboardDomain->dns_status->routable();
            $dashboardDomain->update(['dns_status' => DnsStatus::PENDING]);
            CheckDashboardDomain::dispatch($dashboardDomain);

            if ($wasVerified) {
                // Workers read the root URL when they boot, and the old domain still points at the lost server.
                Artisan::call(RestartCommand::class);
            }
        }

        if ($hostnames->isEmpty()) {
            return 'No domain.';
        }

        return implode(' ', ["Point the DNS of {$hostnames->implode(', ')} at {$cluster->ingress_ip}.", ...$notes]);
    }

    private function syncCaddy(Cluster $cluster): string
    {
        $this->caddy->sync($cluster);

        return 'Caddy has the full config.';
    }

    /** @return Collection<int, Deployment> */
    private function oldDeployments(Cluster $cluster, Carbon $before): Collection
    {
        return Deployment::query()
            ->with('environment')
            ->whereIn('status', [DeploymentStatus::QUEUED, DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING, DeploymentStatus::LIVE])
            ->where('created_at', '<', $before)
            ->whereHas('environment.application', fn (Builder $query) => $query->where('cluster_id', $cluster->id))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, DatastoreAttachment> */
    private function attachments(Cluster $cluster): Collection
    {
        return DatastoreAttachment::query()
            ->with('datastore')
            ->whereHas('datastore', fn (Builder $query) => $query->where('cluster_id', $cluster->id))
            ->orderBy('id')
            ->get();
    }

    private function masker(Cluster $cluster): SecretMasker
    {
        $datastores = Datastore::query()->where('cluster_id', $cluster->id)->get();

        return new SecretMasker(array_filter([
            ...$datastores->pluck('root_password'),
            ...$this->attachments($cluster)->pluck('password'),
            BackupDisk::target()?->secret_key,
        ]));
    }
}

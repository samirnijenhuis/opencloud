<?php

namespace App\Domains\Deployments\Jobs;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Builds\Builder;
use App\Domains\Clusters\ImageCopy;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\DeploymentLog;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\EnvironmentManifests;
use App\Domains\Deployments\EnvironmentVariables;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\Kubectl;
use App\Support\Kube\Labels;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Runs one deployment from start to end. If a step fails, the deployment fails and the old live deployment keeps running.
 */
class RunDeployment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    private const JOB_DEADLINE_SECONDS = 960;

    private const ROLLOUT_TIMEOUT_SECONDS = 300;

    private const DATASTORE_WAIT_SECONDS = 600;

    /** An old pod sleeps 10 seconds in preStop and then stops, so a minute leaves room for a slow app. */
    private const OLD_PODS_WAIT_SECONDS = 60;

    public function __construct(public Deployment $deployment) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("deploy-environment-{$this->deployment->environment_id}"))->releaseAfter(15)->expireAfter($this->timeout + 300)];
    }

    /** Waiting behind another deployment of the same environment releases the job, so tries do not limit it. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(Builder $builder): void
    {
        $deployment = $this->deployment;
        $log = DeploymentLog::for($deployment);

        if ($deployment->status !== DeploymentStatus::QUEUED) {
            // The lock keeps a second run out while the first one lives. So this run follows a run that died midway, and those steps cannot resume.
            $this->markFailed($deployment, $log, 'The worker stopped during the deployment.');

            return;
        }

        try {
            $this->deploy($deployment, $log, $builder);
        } catch (Throwable $e) {
            $this->markFailed($deployment, $log, $e->getMessage());

            return;
        }

        $this->afterLive($deployment, $log);
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed($this->deployment, DeploymentLog::for($this->deployment), $e?->getMessage() ?? 'The deployment job failed.');
    }

    private function deploy(Deployment $deployment, DeploymentLog $log, Builder $builder): void
    {
        $environment = $deployment->environment;
        $application = $environment->application;

        $deployment->started_at = now();
        $deployment->moveTo(DeploymentStatus::BUILDING);

        $deployment->image = ImageCopy::toCluster($deployment, $builder->build($deployment, $log), $log);
        $deployment->moveTo(DeploymentStatus::DEPLOYING);

        $this->waitForDatastores($environment, $log);

        $kubectl = Kubectl::for($application->cluster);
        $manifests = new EnvironmentManifests($environment);
        $kubectl->apply([$manifests->namespace(), $manifests->secret($deployment, EnvironmentVariables::runtime($environment))]);

        if ($environment->deploy_commands !== []) {
            $this->runDeployCommands($kubectl, $manifests, $deployment, $log);
        }

        $log->write('Starting the new version.');
        $kubectl->apply($manifests->workloads($deployment));
        $this->waitForRollout($kubectl, $environment, $log);

        $kubectl->run([
            'delete', 'deployment', '--selector='.$manifests->removedProcessesSelector(), "--namespace={$environment->kubeNamespace()}", '--ignore-not-found', '--wait=false',
        ])->throw();

        foreach ($manifests->absent() as [$kind, $name]) {
            $kubectl->delete($kind, $name, $environment->kubeNamespace());
        }

        $this->waitForOldPods($kubectl, $environment, $deployment, $log);

        DB::transaction(function () use ($deployment) {
            // The database allows one live deployment per environment, so the old one moves first.
            Deployment::query()
                ->where('environment_id', $deployment->environment_id)
                ->where('status', DeploymentStatus::LIVE)
                ->get()
                ->each(fn (Deployment $previous) => $previous->moveTo(DeploymentStatus::SUPERSEDED));

            $deployment->finished_at = now();
            $deployment->moveTo(DeploymentStatus::LIVE);
        });

        $log->write('The deployment is live.');
    }

    /**
     * A rollout ends when the old pods start to stop. Each old pod still answers during its preStop sleep, and Caddy reuses
     * its pooled connections to that pod. Live means that only the new version answers, so the deployment waits for the old pods.
     */
    private function waitForOldPods(Kubectl $kubectl, Environment $environment, Deployment $deployment, DeploymentLog $log): void
    {
        $selector = Labels::ENVIRONMENT."={$environment->id},".Labels::PROCESS.' in ('.implode(',', $environment->processNames()).'),'.Labels::DEPLOYMENT."!={$deployment->id}";
        $deadline = now()->addSeconds(self::OLD_PODS_WAIT_SECONDS);
        $waiting = false;

        while (trim($kubectl->run(['get', 'pods', "--selector={$selector}", "--namespace={$environment->kubeNamespace()}", '-o', 'name'])->throw()->output) !== '') {
            if (now()->greaterThan($deadline)) {
                $log->write('The old pods did not stop within '.self::OLD_PODS_WAIT_SECONDS.' seconds. Kubernetes stops them later.');

                return;
            }

            if (! $waiting) {
                $log->write('Waiting for the old pods to stop.');
                $waiting = true;
            }

            Sleep::for(2)->seconds();
        }
    }

    /** The env Secret holds the datastore credentials, and the deploy commands migrate, so both wait for the datastores. */
    private function waitForDatastores(Environment $environment, DeploymentLog $log): void
    {
        $deadline = now()->addSeconds(self::DATASTORE_WAIT_SECONDS);
        $logged = false;

        while (true) {
            $attachments = $environment->datastoreAttachments()->with('datastore')->get();
            $failed = $attachments->first(fn (DatastoreAttachment $attachment) => $attachment->status === DatastoreStatus::FAILED);

            if ($failed !== null) {
                throw new RuntimeException("The {$failed->datastore->kind->value} datastore failed. {$failed->error}");
            }

            $waiting = $attachments
                ->reject(fn (DatastoreAttachment $attachment) => $attachment->status === DatastoreStatus::READY)
                ->map(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind->value)
                ->implode(', ');

            if ($waiting === '') {
                return;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new RuntimeException('The datastores did not become ready in '.(self::DATASTORE_WAIT_SECONDS / 60)." minutes. Still waiting for: {$waiting}.");
            }

            if (! $logged) {
                $log->write("Waiting for the datastores: {$waiting}.");
                $logged = true;
            }

            Sleep::for(5)->seconds();
        }
    }

    private function runDeployCommands(Kubectl $kubectl, EnvironmentManifests $manifests, Deployment $deployment, DeploymentLog $log): void
    {
        $namespace = $deployment->environment->kubeNamespace();
        $job = 'job/'.EnvironmentManifests::jobName($deployment);

        $log->write('Running the deploy commands: '.implode(' && ', $deployment->environment->deploy_commands));
        $kubectl->apply([$manifests->deployJob($deployment)]);
        $succeeded = $this->waitForJob($kubectl, $namespace, $job);
        $log->write($kubectl->run(['logs', $job, "--namespace={$namespace}", '--all-containers'])->output);

        if (! $succeeded) {
            throw new RuntimeException('A deploy command failed.');
        }
    }

    /** kubectl wait takes one condition, so the loop asks for "complete" and then checks "failed". */
    private function waitForJob(Kubectl $kubectl, string $namespace, string $job): bool
    {
        $deadline = now()->addSeconds(self::JOB_DEADLINE_SECONDS);

        while (true) {
            if ($kubectl->run(['wait', '--for=condition=complete', $job, "--namespace={$namespace}", '--timeout=10s'], timeout: 40)->ok()) {
                return true;
            }

            if ($kubectl->run(['wait', '--for=condition=failed', $job, "--namespace={$namespace}", '--timeout=0s'], timeout: 40)->ok()) {
                return false;
            }

            if (now()->greaterThan($deadline)) {
                throw new RuntimeException('The deploy commands did not finish in time.');
            }
        }
    }

    private function waitForRollout(Kubectl $kubectl, Environment $environment, DeploymentLog $log): void
    {
        $namespace = $environment->kubeNamespace();

        foreach ($environment->processNames() as $process) {
            $result = $kubectl->rolloutStatus($namespace, 'deployment', $process, self::ROLLOUT_TIMEOUT_SECONDS);

            if ($result->ok()) {
                continue;
            }

            $log->write($result->error ?: $result->output);

            foreach ($environment->processNames() as $undo) {
                $kubectl->run(['rollout', 'undo', "deployment/{$undo}", "--namespace={$namespace}"]);
            }

            throw new RuntimeException("The new {$process} pods did not become ready. Opencloud rolled the processes back.");
        }
    }

    /** The deployment is live at this point, so a failure here only adds to the log. */
    private function afterLive(Deployment $deployment, DeploymentLog $log): void
    {
        try {
            SyncCaddy::dispatch($deployment->environment->application->cluster);
            $this->pruneEnvSecrets($deployment);
            $this->checkHomePage($deployment, $log);
        } catch (Throwable $e) {
            $log->write("A step after the deployment went live failed. {$e->getMessage()}");
        }
    }

    /**
     * Keeps the Secret of the live deployment and the two newest, so a rollback still finds its env.
     * The name "env" without an id is the Secret from before each deployment had its own.
     */
    private function pruneEnvSecrets(Deployment $live): void
    {
        $environment = $live->environment;
        $namespace = $environment->kubeNamespace();
        $kubectl = Kubectl::for($environment->application->cluster);
        $secrets = $kubectl->get('secrets', namespace: $namespace, selector: Labels::environment($environment))['items'] ?? [];

        $ids = collect($secrets)
            ->map(fn (array $secret) => $secret['metadata']['name'])
            ->filter(fn (string $name) => preg_match('/^env(-\d+)?$/', $name) === 1)
            ->mapWithKeys(fn (string $name) => [$name => (int) substr($name, 4)])
            ->sortDesc();
        $keep = [...$ids->keys()->take(2), EnvironmentManifests::secretName($live)];

        foreach ($ids->keys()->diff($keep) as $name) {
            $kubectl->delete('secret', $name, $namespace);
        }
    }

    /** /up skips the web middleware, so it can pass while every page fails. One request to / shows that. */
    private function checkHomePage(Deployment $deployment, DeploymentLog $log): void
    {
        $environment = $deployment->environment;
        $application = $environment->application;

        if ($application->type !== ApplicationType::LARAVEL) {
            return;
        }

        $code = "\$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);"
            ."@file_get_contents('http://127.0.0.1:{$application->port}/', false, \$context);"
            ."echo \$http_response_header[0] ?? 'no response';";

        $result = Kubectl::for($application->cluster)
            ->exec($environment->kubeNamespace(), 'deploy/web', ['php', '-r', $code], container: 'web', timeout: 60);

        if (preg_match('/HTTP\/\S+ (\d{3})/', $result->output, $match) === 1 && (int) $match[1] >= 500) {
            $deployment->update(['warning' => "The home page returned HTTP {$match[1]}."]);
            $log->write("Warning: the home page returned HTTP {$match[1]}. Read the web logs.");
        }
    }

    private function markFailed(Deployment $deployment, DeploymentLog $log, string $reason): void
    {
        $deployment->refresh();

        if (! $deployment->status->canMoveTo(DeploymentStatus::FAILED)) {
            return;
        }

        $log->write("The deployment failed. {$reason}");
        $deployment->finished_at = now();
        $deployment->moveTo(DeploymentStatus::FAILED);
    }
}

<?php

namespace App\Jobs;

use App\Build\Builder;
use App\Deploy\DeploymentLog;
use App\Deploy\EnvironmentVariables;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Kube\Kubectl;
use App\Kube\Manifests\EnvironmentManifests;
use App\Models\Deployment;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
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

        if ($deployment->status !== DeploymentStatus::Queued) {
            // The lock outlives a run, so a second run here means the first one died midway. Its steps cannot resume.
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
        $deployment->moveTo(DeploymentStatus::Building);

        $build = $builder->build($deployment, $log);

        if ($application->type !== $build->type) {
            $application->update(['type' => $build->type]);
            $log->write("The repository is a {$build->type->value} app.");
        }

        $deployment->image = $build->image;
        $deployment->moveTo(DeploymentStatus::Deploying);

        $kubectl = Kubectl::for($application->cluster);
        $manifests = new EnvironmentManifests($environment);
        $kubectl->apply([$manifests->namespace(), $manifests->secret(EnvironmentVariables::runtime($environment))]);

        if ($environment->deploy_commands !== []) {
            $this->runDeployCommands($kubectl, $manifests, $deployment, $log);
        }

        $log->write('Starting the new version.');
        $kubectl->apply($manifests->workloads($deployment));
        $this->waitForRollout($kubectl, $manifests, $environment->kubeNamespace(), $log);

        foreach ($manifests->absent() as [$kind, $name]) {
            $kubectl->delete($kind, $name, $environment->kubeNamespace());
        }

        DB::transaction(function () use ($deployment) {
            // The database allows one live deployment per environment, so the old one moves first.
            Deployment::query()
                ->where('environment_id', $deployment->environment_id)
                ->where('status', DeploymentStatus::Live)
                ->get()
                ->each(fn (Deployment $previous) => $previous->moveTo(DeploymentStatus::Superseded));

            $deployment->finished_at = now();
            $deployment->moveTo(DeploymentStatus::Live);
        });

        $log->write('The deployment is live.');
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

    private function waitForRollout(Kubectl $kubectl, EnvironmentManifests $manifests, string $namespace, DeploymentLog $log): void
    {
        foreach ($manifests->processes() as $process) {
            $result = $kubectl->rolloutStatus($namespace, 'deployment', $process->value, self::ROLLOUT_TIMEOUT_SECONDS);

            if ($result->ok()) {
                continue;
            }

            $log->write($result->error ?: $result->output);

            foreach ($manifests->processes() as $undo) {
                $kubectl->run(['rollout', 'undo', "deployment/{$undo->value}", "--namespace={$namespace}"]);
            }

            throw new RuntimeException("The new {$process->value} pods did not become ready. Opencloud rolled the processes back.");
        }
    }

    /** The deployment is live at this point, so a failure here only adds to the log. */
    private function afterLive(Deployment $deployment, DeploymentLog $log): void
    {
        try {
            SyncCaddy::dispatch($deployment->environment->application->cluster);
            $this->checkHomePage($deployment, $log);
        } catch (Throwable $e) {
            $log->write("A step after the deployment went live failed. {$e->getMessage()}");
        }
    }

    /** /up skips the web middleware, so it can pass while every page fails. One request to / shows that. */
    private function checkHomePage(Deployment $deployment, DeploymentLog $log): void
    {
        $environment = $deployment->environment;
        $application = $environment->application;

        if ($application->type !== ApplicationType::Laravel) {
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

        if (! $deployment->status->canMoveTo(DeploymentStatus::Failed)) {
            return;
        }

        $log->write("The deployment failed. {$reason}");
        $deployment->finished_at = now();
        $deployment->moveTo(DeploymentStatus::Failed);
    }
}

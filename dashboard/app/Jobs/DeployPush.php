<?php

namespace App\Jobs;

use App\Actions\StartDeployment;
use App\Enums\EnvironmentKind;
use App\GitHub\PushEvent;
use App\Models\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deploys the pushed commit to each permanent environment of the branch that has auto-deploy on.
 * A preview follows its pull request events instead.
 */
class DeployPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public PushEvent $push) {}

    public function handle(StartDeployment $startDeployment): void
    {
        foreach (Application::forGitHubRepository($this->push->repository) as $application) {
            $environments = $application->environments()
                ->where('kind', EnvironmentKind::Permanent)
                ->where('auto_deploy', true)
                ->where('branch', $this->push->branch)
                ->orderBy('id')
                ->get();

            foreach ($environments as $environment) {
                $startDeployment->handle($environment, null, $this->push->headSha);
            }
        }
    }
}

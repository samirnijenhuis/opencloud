<?php

namespace App\Domains\Deployments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\Git;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use InvalidArgumentException;
use RuntimeException;

class StartDeployment
{
    /**
     * @param  string|null  $commitSha  null deploys the head of the environment branch
     *
     * @throws InvalidArgumentException for the preview template, which is never deployed
     * @throws RuntimeException when the branch does not exist
     */
    public function execute(Environment $environment, ?User $by, ?string $commitSha = null): Deployment
    {
        if ($environment->kind === EnvironmentKind::PREVIEW_TEMPLATE) {
            throw new InvalidArgumentException('The preview template holds settings for previews. It is never deployed.');
        }

        if ($commitSha === null && $environment->branch === null) {
            throw new InvalidArgumentException("Environment {$environment->name} has no branch to deploy.");
        }

        $commitSha ??= Git::head($environment->application->cloneUrl(), $environment->branch);

        $deployment = $environment->deployments()->create(['commit_sha' => $commitSha, 'triggered_by' => $by?->id]);

        AuditEvent::record($by, 'deployment.started', $deployment, ['environment_id' => $environment->id, 'commit_sha' => $commitSha]);
        RunDeployment::dispatch($deployment)->onQueue('builds')->afterCommit();

        return $deployment;
    }
}

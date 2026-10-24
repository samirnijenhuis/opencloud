<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;

trait PresentsEnvironment
{
    /**
     * The props that every page of an environment shows in its header.
     *
     * @return array<string, mixed>
     */
    protected function environmentProps(Environment $environment, User $user): array
    {
        $application = $environment->application;
        $latest = $environment->deployments()->latest('id')->first();
        $live = $environment->deployments()->where('status', DeploymentStatus::Live)->first();
        $hostnames = $environment->domains()->orderBy('id')->pluck('hostname');

        return [
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'repository' => $application->repository,
                'type' => $application->type,
                // The preview template is not a place to switch to. Its page opens from the application.
                'environments' => $application->environments()
                    ->where('kind', '!=', EnvironmentKind::PreviewTemplate)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Environment $each) => ['id' => $each->id, 'name' => $each->name, 'kind' => $each->kind, 'color' => $each->color])
                    ->all(),
            ],
            'environment' => [
                'id' => $environment->id,
                'name' => $environment->name,
                'kind' => $environment->kind,
                'is_protected' => $environment->is_protected,
                'color' => $environment->color,
                'branch' => $environment->branch,
                'auto_deploy' => $environment->auto_deploy,
                'status' => $latest?->status,
                'live_commit' => $live?->commit_sha,
                'urls' => $hostnames->map(fn (string $hostname) => "https://{$hostname}")->all(),
            ],
            'can' => [
                'update' => $user->can('update', $environment),
                'deploy' => $user->can('deploy', $environment),
                'manage_domains' => $user->can('update', $application),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function deploymentProps(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'commit_sha' => $deployment->commit_sha,
            'triggered_by' => $deployment->triggeredBy?->name,
            'created_at' => $deployment->created_at->toIso8601String(),
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'warning' => $deployment->warning,
        ];
    }
}

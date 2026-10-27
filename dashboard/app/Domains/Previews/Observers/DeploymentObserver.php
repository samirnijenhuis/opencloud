<?php

namespace App\Domains\Previews\Observers;

use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Previews\Jobs\PostPreviewComment;

class DeploymentObserver
{
    public function updated(Deployment $deployment): void
    {
        if ($deployment->wasChanged('status') && $deployment->status === DeploymentStatus::Live && $deployment->environment->kind === EnvironmentKind::Preview) {
            PostPreviewComment::dispatch($deployment)->afterCommit();
        }
    }
}

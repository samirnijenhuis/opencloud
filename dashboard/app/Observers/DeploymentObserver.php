<?php

namespace App\Observers;

use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Jobs\PostPreviewComment;
use App\Models\Deployment;

class DeploymentObserver
{
    public function updated(Deployment $deployment): void
    {
        if ($deployment->wasChanged('status') && $deployment->status === DeploymentStatus::Live && $deployment->environment->kind === EnvironmentKind::Preview) {
            PostPreviewComment::dispatch($deployment)->afterCommit();
        }
    }
}

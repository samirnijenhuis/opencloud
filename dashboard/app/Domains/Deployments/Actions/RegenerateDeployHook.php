<?php

namespace App\Domains\Deployments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\DeployHook;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

class RegenerateDeployHook
{
    /** The old URL stops working at once. The hook stays enabled or disabled. */
    public function execute(Environment $environment, User $by): Environment
    {
        $environment->deploy_hook_token = DeployHook::newToken();
        $environment->save();

        AuditEvent::record($by, 'deploy_hook.regenerated', $environment);

        return $environment;
    }
}

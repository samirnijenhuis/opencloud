<?php

namespace App\Actions;

use App\Environments\DeployHook;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;

class RegenerateDeployHook
{
    /** The old URL stops working at once. The hook stays enabled or disabled. */
    public function handle(Environment $environment, User $by): Environment
    {
        $environment->deploy_hook_token = DeployHook::newToken();
        $environment->save();

        AuditEvent::record($by, 'deploy_hook.regenerated', $environment);

        return $environment;
    }
}

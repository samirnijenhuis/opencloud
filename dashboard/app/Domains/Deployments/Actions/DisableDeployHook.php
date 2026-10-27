<?php

namespace App\Domains\Deployments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

class DisableDeployHook
{
    public function execute(Environment $environment, User $by): Environment
    {
        if (! $environment->deploy_hook_enabled) {
            return $environment;
        }

        $environment->update(['deploy_hook_enabled' => false]);

        AuditEvent::record($by, 'deploy_hook.disabled', $environment);

        return $environment;
    }
}

<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;

class DisableDeployHook
{
    public function handle(Environment $environment, User $by): Environment
    {
        if (! $environment->deploy_hook_enabled) {
            return $environment;
        }

        $environment->update(['deploy_hook_enabled' => false]);

        AuditEvent::record($by, 'deploy_hook.disabled', $environment);

        return $environment;
    }
}

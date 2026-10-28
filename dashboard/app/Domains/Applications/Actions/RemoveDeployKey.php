<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;

class RemoveDeployKey
{
    /** Git then reads the repository with the GitHub App token, or with the plain URL. */
    public function execute(Application $application, User $by): void
    {
        $application->update(['deploy_private_key' => null, 'deploy_public_key' => null]);

        AuditEvent::record($by, 'application.deploy_key_removed', $application);
    }
}

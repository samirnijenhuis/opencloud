<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\SshKeygen;
use App\Domains\Members\Models\User;

class AddDeployKey
{
    public function __construct(private SshKeygen $sshKeygen) {}

    /** A new key replaces the old one at once, so this also rotates the key. GitHub keeps the old key until the user deletes it there. */
    public function execute(Application $application, User $by): void
    {
        $key = $this->sshKeygen->keyPair("opencloud {$application->name}");

        $application->update(['deploy_private_key' => $key['private'], 'deploy_public_key' => $key['public']]);

        AuditEvent::record($by, 'application.deploy_key_added', $application);
    }
}

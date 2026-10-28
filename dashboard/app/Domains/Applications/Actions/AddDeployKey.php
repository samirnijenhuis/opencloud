<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\SshKeygen;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class AddDeployKey
{
    public function __construct(private SshKeygen $sshKeygen) {}

    /**
     * Without $privateKey, it makes a new key pair. With it, it stores the key of the user, which GitHub can already know.
     * A key replaces the old one at once, so this also rotates the key. GitHub keeps the old key until the user deletes it there.
     *
     * @throws ValidationException when ssh-keygen cannot read $privateKey
     */
    public function execute(Application $application, User $by, ?string $privateKey = null): void
    {
        $key = $privateKey === null ? $this->sshKeygen->keyPair("opencloud {$application->name}") : $this->sshKeygen->keyPairOf($privateKey);

        $application->update(['deploy_private_key' => $key['private'], 'deploy_public_key' => $key['public']]);

        AuditEvent::record($by, 'application.deploy_key_added', $application, ['source' => $privateKey === null ? 'new' : 'own']);
    }
}

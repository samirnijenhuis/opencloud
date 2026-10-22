<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\ProviderCredential;
use App\Models\User;

class DeleteProviderCredential
{
    public function handle(ProviderCredential $credential, User $by): void
    {
        $credential->delete();

        AuditEvent::record($by, 'provider_credential.deleted', null, ['provider' => $credential->provider]);
    }
}

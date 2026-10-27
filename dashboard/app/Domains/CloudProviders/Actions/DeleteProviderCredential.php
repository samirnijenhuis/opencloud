<?php

namespace App\Domains\CloudProviders\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Members\Models\User;

class DeleteProviderCredential
{
    public function execute(ProviderCredential $credential, User $by): void
    {
        $credential->delete();

        AuditEvent::record($by, 'provider_credential.deleted', null, ['provider' => $credential->provider]);
    }
}

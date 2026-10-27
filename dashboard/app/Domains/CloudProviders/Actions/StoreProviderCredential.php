<?php

namespace App\Domains\CloudProviders\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\CloudProviders\Providers;
use App\Domains\Members\Models\User;

class StoreProviderCredential
{
    /**
     * Checks the key with the provider first. The key is stored only when the check passes or only optional rights are missing.
     */
    public function execute(User $by, string $provider, string $token): CheckResult
    {
        $guide = Providers::find($provider);
        $token = trim($token);
        $result = $guide->check($token)->withoutSecret($token);

        if ($guide->accepts($result)) {
            $credential = ProviderCredential::query()->updateOrCreate(['provider' => $provider], [
                'token' => $token,
                'checked_at' => now(),
                'missing_rights' => $result->missing,
                'details' => $result->details ?: null,
            ]);

            AuditEvent::record($by, 'provider_credential.stored', $credential, [
                'provider' => $provider,
                'check' => $result->status->value,
                'missing_rights' => $result->missing,
            ]);
        }

        return $result;
    }
}

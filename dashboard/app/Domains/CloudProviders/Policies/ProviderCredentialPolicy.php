<?php

namespace App\Domains\CloudProviders\Policies;

use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Models\User;

class ProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    public function update(User $user, ProviderCredential $credential): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }

    public function delete(User $user, ProviderCredential $credential): bool
    {
        return $user->role->allows(Ability::MANAGE_CREDENTIALS);
    }
}

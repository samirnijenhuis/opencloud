<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\ProviderCredential;
use App\Models\User;

class ProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }

    public function update(User $user, ProviderCredential $credential): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }

    public function delete(User $user, ProviderCredential $credential): bool
    {
        return $user->role->allows(Ability::ManageCredentials);
    }
}

<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    public function view(User $user, Environment $environment): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }

    /**
     * Covers settings, deploy commands, and env values, plain or secret.
     */
    public function update(User $user, Environment $environment): bool
    {
        return $user->role->allows($environment->is_protected
            ? Ability::ChangeProtectedEnvironment
            : Ability::ChangeUnprotectedEnvironment);
    }

    public function delete(User $user, Environment $environment): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }
}

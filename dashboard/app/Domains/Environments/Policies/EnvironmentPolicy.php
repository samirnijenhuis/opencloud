<?php

namespace App\Domains\Environments\Policies;

use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Models\User;

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

    /**
     * A developer can deploy previews and staging, but production deploys need someone trusted with production secrets.
     */
    public function deploy(User $user, Environment $environment): bool
    {
        return $user->role->allows($environment->is_protected
            ? Ability::ChangeProtectedEnvironment
            : Ability::Deploy);
    }

    public function delete(User $user, Environment $environment): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }
}

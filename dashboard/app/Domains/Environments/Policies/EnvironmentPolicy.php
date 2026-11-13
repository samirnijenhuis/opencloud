<?php

namespace App\Domains\Environments\Policies;

use App\Domains\Environments\Enums\EnvironmentKind;
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
        return $user->role->allows(Ability::MANAGE_APPLICATIONS);
    }

    /**
     * Covers settings, deploy commands, and env values, plain or secret.
     */
    public function update(User $user, Environment $environment): bool
    {
        return $user->role->allows(Ability::CHANGE_ENVIRONMENT);
    }

    public function deploy(User $user, Environment $environment): bool
    {
        return $user->role->allows(Ability::DEPLOY);
    }

    public function readLogs(User $user, Environment $environment): bool
    {
        return $user->role->allows(Ability::READ_LOGS);
    }

    /**
     * Only a permanent environment can go, production too.
     * A preview goes with its pull request, and the preview template goes with its application.
     */
    public function delete(User $user, Environment $environment): bool
    {
        if ($environment->kind !== EnvironmentKind::PERMANENT) {
            return false;
        }

        return $user->role->allows(Ability::MANAGE_APPLICATIONS);
    }
}

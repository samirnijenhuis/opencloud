<?php

namespace App\Domains\Applications\Policies;

use App\Domains\Applications\Models\Application;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Models\User;

class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::MANAGE_APPLICATIONS);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->role->allows(Ability::MANAGE_APPLICATIONS);
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->role->allows(Ability::MANAGE_APPLICATIONS);
    }
}

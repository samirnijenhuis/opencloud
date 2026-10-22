<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->role->allows(Ability::ManageApplications);
    }
}

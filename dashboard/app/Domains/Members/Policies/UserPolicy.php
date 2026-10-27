<?php

namespace App\Domains\Members\Policies;

use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function invite(User $user): bool
    {
        return $user->role->allows(Ability::ManageMembers);
    }

    /**
     * Nobody changes their own role, so the acting owner always stays an owner.
     */
    public function update(User $user, User $member): bool
    {
        return $user->role->allows(Ability::ManageMembers) && ! $user->is($member);
    }

    public function delete(User $user, User $member): bool
    {
        return $user->role->allows(Ability::ManageMembers) && ! $user->is($member);
    }
}

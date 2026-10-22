<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;

class ChangeMemberRole
{
    public function handle(User $member, User $by, Role $role): void
    {
        $from = $member->role;

        $member->role = $role;
        $member->save();

        AuditEvent::record($by, 'member.role_changed', $member, ['from' => $from->value, 'to' => $role->value]);
    }
}

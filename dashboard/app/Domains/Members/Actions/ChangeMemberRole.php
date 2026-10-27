<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;

class ChangeMemberRole
{
    public function execute(User $member, User $by, Role $role): void
    {
        $from = $member->role;

        $member->role = $role;
        $member->save();

        AuditEvent::record($by, 'member.role_changed', $member, ['from' => $from->value, 'to' => $role->value]);
    }
}

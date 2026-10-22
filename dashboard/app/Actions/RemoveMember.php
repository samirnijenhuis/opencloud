<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\User;

class RemoveMember
{
    public function handle(User $member, User $by): void
    {
        $member->delete();

        AuditEvent::record($by, 'member.removed', $member, ['email' => $member->email, 'role' => $member->role->value]);
    }
}

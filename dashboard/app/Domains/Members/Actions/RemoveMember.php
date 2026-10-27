<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;

class RemoveMember
{
    public function execute(User $member, User $by): void
    {
        $member->delete();

        AuditEvent::record($by, 'member.removed', $member, ['email' => $member->email, 'role' => $member->role->value]);
    }
}

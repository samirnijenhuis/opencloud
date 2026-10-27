<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\Invite;
use App\Domains\Members\Models\User;

class RevokeInvite
{
    public function handle(Invite $invite, User $by): void
    {
        $invite->delete();

        AuditEvent::record($by, 'invite.revoked', $invite, ['role' => $invite->role->value]);
    }
}

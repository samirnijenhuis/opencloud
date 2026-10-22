<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\User;

class RevokeInvite
{
    public function handle(Invite $invite, User $by): void
    {
        $invite->delete();

        AuditEvent::record($by, 'invite.revoked', $invite, ['role' => $invite->role->value]);
    }
}

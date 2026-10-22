<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\User;

class IssueInvite
{
    /** @return array{Invite, string} The invite and the plain token for its link. */
    public function handle(User $by, Role $role): array
    {
        [$invite, $token] = Invite::issue($role);

        AuditEvent::record($by, 'invite.created', $invite, ['role' => $role->value]);

        return [$invite, $token];
    }
}

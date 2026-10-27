<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\Invite;
use App\Domains\Members\Models\User;

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

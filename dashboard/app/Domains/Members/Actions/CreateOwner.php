<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;

class CreateOwner
{
    /** @param array{name: string, email: string, password: string} $data */
    public function handle(array $data): User
    {
        $owner = new User($data);
        $owner->role = Role::Owner;
        $owner->save();

        AuditEvent::record($owner, 'owner.created', $owner);

        return $owner;
    }
}

<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;

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

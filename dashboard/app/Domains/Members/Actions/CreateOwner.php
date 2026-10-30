<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

class CreateOwner
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * The check and the insert share one transaction, which takes the SQLite write lock at its start.
     * So two setup requests at the same time cannot both create an owner.
     *
     * @param  array{name: string, email: string, password: string}  $data
     *
     * @throws ValidationException when a user exists already
     */
    public function execute(array $data): User
    {
        return $this->db->transaction(function () use ($data) {
            if (User::query()->exists()) {
                throw ValidationException::withMessages(['email' => 'Opencloud has an owner already. Sign in instead.']);
            }

            $owner = new User($data);
            $owner->role = Role::OWNER;
            $owner->save();

            AuditEvent::record($owner, 'owner.created', $owner);

            return $owner;
        });
    }
}

<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvite
{
    /** @param array{name: string, email: string, password: string} $data */
    public function handle(Invite $invite, array $data): User
    {
        return DB::transaction(function () use ($invite, $data) {
            // Delete first and count the rows, so two requests with one link cannot both create a member.
            abort_if(Invite::whereKey($invite->id)->delete() === 0, 404);

            $member = new User($data);
            $member->role = $invite->role;
            $member->save();

            AuditEvent::record($member, 'member.joined', $member, ['role' => $member->role->value]);

            return $member;
        });
    }
}

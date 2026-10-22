<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';

    public function allows(Ability $ability): bool
    {
        $roles = match ($ability) {
            Ability::ManageCredentials,
            Ability::ManageMembers => [self::Owner],
            Ability::ManageApplications,
            Ability::ChangeProtectedEnvironment => [self::Owner, self::Admin],
            Ability::ChangeUnprotectedEnvironment,
            Ability::Deploy,
            Ability::ReadLogs => [self::Owner, self::Admin, self::Developer],
        };

        return in_array($this, $roles, true);
    }
}

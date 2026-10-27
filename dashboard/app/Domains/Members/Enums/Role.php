<?php

namespace App\Domains\Members\Enums;

enum Role: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case DEVELOPER = 'developer';

    public function allows(Ability $ability): bool
    {
        $roles = match ($ability) {
            Ability::MANAGE_CREDENTIALS,
            Ability::MANAGE_MEMBERS,
            Ability::UPGRADE_OPENCLOUD => [self::OWNER],
            Ability::MANAGE_APPLICATIONS,
            Ability::CHANGE_PROTECTED_ENVIRONMENT => [self::OWNER, self::ADMIN],
            Ability::CHANGE_UNPROTECTED_ENVIRONMENT,
            Ability::DEPLOY,
            Ability::READ_LOGS => [self::OWNER, self::ADMIN, self::DEVELOPER],
        };

        return in_array($this, $roles, true);
    }
}

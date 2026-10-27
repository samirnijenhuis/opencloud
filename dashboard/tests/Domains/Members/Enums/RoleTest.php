<?php

namespace Tests\Domains\Members\Enums;

use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    /** @return array<string, array{Role, Ability, bool}> */
    public static function rights(): array
    {
        return [
            'owner manages credentials' => [Role::OWNER, Ability::MANAGE_CREDENTIALS, true],
            'owner manages members' => [Role::OWNER, Ability::MANAGE_MEMBERS, true],
            'owner upgrades opencloud' => [Role::OWNER, Ability::UPGRADE_OPENCLOUD, true],
            'owner manages applications' => [Role::OWNER, Ability::MANAGE_APPLICATIONS, true],
            'owner changes protected environment' => [Role::OWNER, Ability::CHANGE_PROTECTED_ENVIRONMENT, true],
            'owner changes unprotected environment' => [Role::OWNER, Ability::CHANGE_UNPROTECTED_ENVIRONMENT, true],
            'owner deploys' => [Role::OWNER, Ability::DEPLOY, true],
            'owner reads logs' => [Role::OWNER, Ability::READ_LOGS, true],

            'admin manages credentials' => [Role::ADMIN, Ability::MANAGE_CREDENTIALS, false],
            'admin manages members' => [Role::ADMIN, Ability::MANAGE_MEMBERS, false],
            'admin upgrades opencloud' => [Role::ADMIN, Ability::UPGRADE_OPENCLOUD, false],
            'admin manages applications' => [Role::ADMIN, Ability::MANAGE_APPLICATIONS, true],
            'admin changes protected environment' => [Role::ADMIN, Ability::CHANGE_PROTECTED_ENVIRONMENT, true],
            'admin changes unprotected environment' => [Role::ADMIN, Ability::CHANGE_UNPROTECTED_ENVIRONMENT, true],
            'admin deploys' => [Role::ADMIN, Ability::DEPLOY, true],
            'admin reads logs' => [Role::ADMIN, Ability::READ_LOGS, true],

            'developer manages credentials' => [Role::DEVELOPER, Ability::MANAGE_CREDENTIALS, false],
            'developer manages members' => [Role::DEVELOPER, Ability::MANAGE_MEMBERS, false],
            'developer upgrades opencloud' => [Role::DEVELOPER, Ability::UPGRADE_OPENCLOUD, false],
            'developer manages applications' => [Role::DEVELOPER, Ability::MANAGE_APPLICATIONS, false],
            'developer changes protected environment' => [Role::DEVELOPER, Ability::CHANGE_PROTECTED_ENVIRONMENT, false],
            'developer changes unprotected environment' => [Role::DEVELOPER, Ability::CHANGE_UNPROTECTED_ENVIRONMENT, true],
            'developer deploys' => [Role::DEVELOPER, Ability::DEPLOY, true],
            'developer reads logs' => [Role::DEVELOPER, Ability::READ_LOGS, true],
        ];
    }

    #[DataProvider('rights')]
    public function test_role_allows_exactly_the_rights_in_the_design(Role $role, Ability $ability, bool $expected): void
    {
        $this->assertSame($expected, $role->allows($ability));
    }

    public function test_the_rights_table_covers_every_role_and_ability(): void
    {
        $covered = array_map(
            fn (array $row) => $row[0]->name.'.'.$row[1]->name,
            array_values(self::rights()),
        );

        $all = [];
        foreach (Role::cases() as $role) {
            foreach (Ability::cases() as $ability) {
                $all[] = $role->name.'.'.$ability->name;
            }
        }

        $this->assertEqualsCanonicalizing($all, $covered);
    }
}

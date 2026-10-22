<?php

namespace Tests\Unit;

use App\Enums\Ability;
use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    /** @return array<string, array{Role, Ability, bool}> */
    public static function rights(): array
    {
        return [
            'owner manages credentials' => [Role::Owner, Ability::ManageCredentials, true],
            'owner manages members' => [Role::Owner, Ability::ManageMembers, true],
            'owner manages applications' => [Role::Owner, Ability::ManageApplications, true],
            'owner changes protected environment' => [Role::Owner, Ability::ChangeProtectedEnvironment, true],
            'owner changes unprotected environment' => [Role::Owner, Ability::ChangeUnprotectedEnvironment, true],
            'owner deploys' => [Role::Owner, Ability::Deploy, true],
            'owner reads logs' => [Role::Owner, Ability::ReadLogs, true],

            'admin manages credentials' => [Role::Admin, Ability::ManageCredentials, false],
            'admin manages members' => [Role::Admin, Ability::ManageMembers, false],
            'admin manages applications' => [Role::Admin, Ability::ManageApplications, true],
            'admin changes protected environment' => [Role::Admin, Ability::ChangeProtectedEnvironment, true],
            'admin changes unprotected environment' => [Role::Admin, Ability::ChangeUnprotectedEnvironment, true],
            'admin deploys' => [Role::Admin, Ability::Deploy, true],
            'admin reads logs' => [Role::Admin, Ability::ReadLogs, true],

            'developer manages credentials' => [Role::Developer, Ability::ManageCredentials, false],
            'developer manages members' => [Role::Developer, Ability::ManageMembers, false],
            'developer manages applications' => [Role::Developer, Ability::ManageApplications, false],
            'developer changes protected environment' => [Role::Developer, Ability::ChangeProtectedEnvironment, false],
            'developer changes unprotected environment' => [Role::Developer, Ability::ChangeUnprotectedEnvironment, true],
            'developer deploys' => [Role::Developer, Ability::Deploy, true],
            'developer reads logs' => [Role::Developer, Ability::ReadLogs, true],
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

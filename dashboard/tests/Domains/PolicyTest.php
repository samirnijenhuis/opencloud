<?php

namespace Tests\Domains;

use App\Domains\Applications\Models\Application;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Role, bool, bool}> */
    public static function environmentUpdates(): array
    {
        return [
            'owner, protected' => [Role::Owner, true, true],
            'owner, unprotected' => [Role::Owner, false, true],
            'admin, protected' => [Role::Admin, true, true],
            'admin, unprotected' => [Role::Admin, false, true],
            'developer, protected' => [Role::Developer, true, false],
            'developer, unprotected' => [Role::Developer, false, true],
        ];
    }

    #[DataProvider('environmentUpdates')]
    public function test_only_owners_and_admins_change_or_deploy_a_protected_environment(Role $role, bool $protected, bool $expected): void
    {
        $user = User::factory()->create(['role' => $role]);
        $environment = Environment::factory()->create(['is_protected' => $protected]);

        $this->assertSame($expected, $user->can('update', $environment));
        $this->assertSame($expected, $user->can('deploy', $environment));
    }

    /** @return array<string, array{Role, array<string, bool>}> */
    public static function roleRights(): array
    {
        return [
            'owner' => [Role::Owner, [
                'create application' => true,
                'delete application' => true,
                'create environment' => true,
                'create cluster' => true,
                'update cluster' => true,
                'list provider credentials' => true,
                'create provider credential' => true,
                'invite member' => true,
            ]],
            'admin' => [Role::Admin, [
                'create application' => true,
                'delete application' => true,
                'create environment' => true,
                'create cluster' => false,
                'update cluster' => false,
                'list provider credentials' => false,
                'create provider credential' => false,
                'invite member' => false,
            ]],
            'developer' => [Role::Developer, [
                'create application' => false,
                'delete application' => false,
                'create environment' => false,
                'create cluster' => false,
                'update cluster' => false,
                'list provider credentials' => false,
                'create provider credential' => false,
                'invite member' => false,
            ]],
        ];
    }

    /** @param array<string, bool> $expected */
    #[DataProvider('roleRights')]
    public function test_each_policy_follows_the_role_matrix(Role $role, array $expected): void
    {
        $user = User::factory()->create(['role' => $role]);
        $application = Application::factory()->create();
        $cluster = Cluster::factory()->create();
        ProviderCredential::factory()->create();

        $this->assertSame($expected, [
            'create application' => $user->can('create', Application::class),
            'delete application' => $user->can('delete', $application),
            'create environment' => $user->can('create', Environment::class),
            'create cluster' => $user->can('create', Cluster::class),
            'update cluster' => $user->can('update', $cluster),
            'list provider credentials' => $user->can('viewAny', ProviderCredential::class),
            'create provider credential' => $user->can('create', ProviderCredential::class),
            'invite member' => $user->can('invite', User::class),
        ]);
    }

    public function test_an_owner_manages_other_members_but_not_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $developer = User::factory()->create();

        $this->assertTrue($owner->can('update', $developer));
        $this->assertTrue($owner->can('delete', $developer));
        $this->assertFalse($owner->can('update', $owner));
        $this->assertFalse($owner->can('delete', $owner));
        $this->assertFalse($admin->can('update', $developer));
        $this->assertFalse($admin->can('delete', $developer));
        $this->assertTrue($developer->can('viewAny', User::class));
    }
}

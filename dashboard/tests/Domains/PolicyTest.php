<?php

namespace Tests\Domains;

use App\Domains\Applications\Models\Application;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Role, string}> */
    public static function environmentUpdates(): array
    {
        return [
            'owner, production' => [Role::OWNER, 'production'],
            'owner, staging' => [Role::OWNER, 'staging'],
            'admin, production' => [Role::ADMIN, 'production'],
            'admin, staging' => [Role::ADMIN, 'staging'],
            'developer, production' => [Role::DEVELOPER, 'production'],
            'developer, staging' => [Role::DEVELOPER, 'staging'],
        ];
    }

    #[DataProvider('environmentUpdates')]
    public function test_each_role_changes_and_deploys_every_environment_production_too(Role $role, string $name): void
    {
        $user = User::factory()->create(['role' => $role]);
        $environment = Environment::factory()->create(['name' => $name]);

        $this->assertTrue($user->can('update', $environment));
        $this->assertTrue($user->can('deploy', $environment));
    }

    /** @return array<string, array{Role, array<string, bool>}> */
    public static function environmentDeletes(): array
    {
        return [
            'owner' => [Role::OWNER, ['production' => true, 'staging' => true, 'preview' => false, 'preview template' => false]],
            'admin' => [Role::ADMIN, ['production' => true, 'staging' => true, 'preview' => false, 'preview template' => false]],
            'developer' => [Role::DEVELOPER, ['production' => false, 'staging' => false, 'preview' => false, 'preview template' => false]],
        ];
    }

    /** @param array<string, bool> $expected */
    #[DataProvider('environmentDeletes')]
    public function test_owners_and_admins_delete_a_permanent_environment_and_nobody_deletes_a_preview_or_the_template(Role $role, array $expected): void
    {
        $user = User::factory()->create(['role' => $role]);
        $application = Application::factory()->create();
        $environment = fn (array $attributes) => Environment::factory()->for($application)->create($attributes);

        $this->assertSame($expected, [
            'production' => $user->can('delete', $environment(['name' => 'production', 'kind' => EnvironmentKind::PERMANENT])),
            'staging' => $user->can('delete', $environment(['name' => 'staging', 'kind' => EnvironmentKind::PERMANENT])),
            'preview' => $user->can('delete', $environment(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12])),
            'preview template' => $user->can('delete', $environment(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE])),
        ]);
    }

    /** @return array<string, array{Role, array<string, bool>}> */
    public static function roleRights(): array
    {
        return [
            'owner' => [Role::OWNER, [
                'create application' => true,
                'delete application' => true,
                'create environment' => true,
                'create cluster' => true,
                'update cluster' => true,
                'list provider credentials' => true,
                'create provider credential' => true,
                'invite member' => true,
            ]],
            'admin' => [Role::ADMIN, [
                'create application' => true,
                'delete application' => true,
                'create environment' => true,
                'create cluster' => false,
                'update cluster' => false,
                'list provider credentials' => false,
                'create provider credential' => false,
                'invite member' => false,
            ]],
            'developer' => [Role::DEVELOPER, [
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

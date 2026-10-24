<?php

namespace Tests\Feature\Models;

use App\Domains\DnsStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DnsMode;
use App\Enums\EnvironmentKind;
use App\Enums\Role;
use App\Enums\Size;
use App\Models\Application;
use App\Models\BackupTarget;
use App\Models\Cluster;
use App\Models\DatastoreAttachment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\GithubApp;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_environment_gets_the_design_defaults(): void
    {
        $environment = Environment::create([
            'application_id' => Application::factory()->create()->id,
            'name' => 'production',
            'kind' => EnvironmentKind::Permanent,
        ])->fresh();

        $this->assertSame(Size::S, $environment->size);
        $this->assertSame(1, $environment->min_instances);
        $this->assertSame(1, $environment->max_instances);
        $this->assertSame(70, $environment->cpu_target);
        $this->assertSame('/up', $environment->health_path);
        $this->assertSame([], $environment->deploy_commands);
        $this->assertTrue($environment->auto_deploy);
        $this->assertFalse($environment->is_protected);
    }

    public function test_a_new_user_is_a_developer(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password']);

        $this->assertSame(Role::Developer, $user->role);
        $this->assertSame('developer', DB::table('users')->where('id', $user->id)->value('role'));
    }

    public function test_the_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret-password', 'role' => 'owner']);

        $this->assertSame(Role::Developer, $user->fresh()->role);
    }

    public function test_a_new_domain_waits_for_dns_in_manual_mode(): void
    {
        $domain = Domain::factory()->create(['hostname' => 'shop.example.com'])->fresh();

        $this->assertSame(DnsMode::Manual, $domain->dns_mode);
        $this->assertSame(DnsStatus::Pending, $domain->dns_status);
        $this->assertSame('pending', $domain->certificate_status);
    }

    /** @return array<string, array{0: class-string<Model>, 1: string, 2: string, 3?: array<string, mixed>}> */
    public static function encryptedFields(): array
    {
        return [
            'github app private key' => [GithubApp::class, 'private_key', 'pem-private-key-text'],
            'github app webhook secret' => [GithubApp::class, 'webhook_secret', 'webhook-secret-text'],
            'provider token' => [ProviderCredential::class, 'token', 'dop_v1_provider-token'],
            'cluster kubeconfig' => [Cluster::class, 'kubeconfig', 'kubeconfig-with-client-key'],
            'secret env var value' => [EnvVar::class, 'value', 'env-value-text', ['is_secret' => true]],
            'datastore password' => [DatastoreAttachment::class, 'password', 'datastore-password-text'],
            'backup access key' => [BackupTarget::class, 'access_key', 'backup-access-key'],
            'backup secret key' => [BackupTarget::class, 'secret_key', 'backup-secret-key'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('encryptedFields')]
    public function test_a_sensitive_field_is_encrypted_at_rest_and_hidden(string $model, string $field, string $plain, array $extra = []): void
    {
        $record = $model::factory()->create([$field => $plain, ...$extra]);

        $stored = DB::table($record->getTable())->where('id', $record->id)->value($field);

        $this->assertStringNotContainsString($plain, $stored);
        $this->assertSame($plain, $record->fresh()->{$field});
        $this->assertStringNotContainsString($plain, $record->fresh()->toJson());
    }

    public function test_an_environment_has_at_most_one_live_deployment(): void
    {
        $environment = Environment::factory()->create();
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Live]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Superseded]);
        Deployment::factory()->create(['status' => DeploymentStatus::Live]);

        $this->expectException(QueryException::class);

        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Live]);
    }
}

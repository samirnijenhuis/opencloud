<?php

namespace Tests\Feature\Http;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Datastores\DatastoreStatus;
use App\Enums\DatastoreKind;
use App\Enums\EnvVarSource;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BackupTarget;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class DatastoreControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private function attach(Environment $environment, DatastoreKind $kind, DatastoreStatus $status = DatastoreStatus::Ready, array $attributes = []): DatastoreAttachment
    {
        $datastore = Datastore::factory()->create(['cluster_id' => $this->makeCluster()->id, 'kind' => $kind, 'status' => $status->value, 'root_password' => 'root-password-xyz']);

        return DatastoreAttachment::factory()->create([
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            'password' => 'attachment-password-abc',
            'status' => $status,
            ...$attributes,
        ]);
    }

    public function test_the_page_lists_attachments_with_status_error_server_and_env_keys_only(): void
    {
        $environment = $this->makeEnvironment();
        $failed = $this->attach($environment, DatastoreKind::Valkey, DatastoreStatus::Failed, ['error' => 'The pod did not start.']);
        EnvVar::factory()->for($environment)->create(['key' => 'REDIS_PASSWORD', 'value' => 'attachment-password-abc', 'source' => EnvVarSource::Datastore, 'is_secret' => true, 'datastore_attachment_id' => $failed->id]);
        EnvVar::factory()->for($environment)->create(['key' => 'REDIS_HOST', 'value' => 'valkey.internal.example', 'source' => EnvVarSource::Datastore, 'datastore_attachment_id' => $failed->id]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/datastores")->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('environments/datastores')
            ->has('attachments', 1)
            ->where('attachments.0.kind', 'valkey')
            ->where('attachments.0.status', 'failed')
            ->where('attachments.0.error', 'The pod did not start.')
            ->where('attachments.0.server.cluster', 'local')
            ->where('attachments.0.env_keys', ['REDIS_HOST', 'REDIS_PASSWORD'])
            ->where('available_kinds', ['mysql', 'postgres', 'rustfs'])
            ->where('has_backup_target', false)
            ->where('polling', false)
            ->where('can.update', true));

        $raw = $response->getContent();
        foreach (['attachment-password-abc', 'root-password-xyz', 'valkey.internal.example'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_the_inertia_json_response_never_contains_a_secret(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::Mysql);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'attachment-password-abc', 'source' => EnvVarSource::Datastore, 'is_secret' => true, 'datastore_attachment_id' => $attachment->id]);
        BackupTarget::factory()->create(['access_key' => 'AKIATARGETACCESS', 'secret_key' => 'target-secret-key-123']);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get("/environments/{$environment->id}/datastores", ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->assertOk();

        foreach (['attachment-password-abc', 'root-password-xyz', 'AKIATARGETACCESS', 'target-secret-key-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertStringContainsString('DB_PASSWORD', $response->getContent());
    }

    public function test_the_page_polls_while_an_attachment_is_pending_or_provisioning(): void
    {
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();
        $attachment = $this->attach($environment, DatastoreKind::Mysql, DatastoreStatus::Pending);

        foreach ([DatastoreStatus::Pending, DatastoreStatus::Provisioning] as $status) {
            $attachment->update(['status' => $status]);
            $this->actingAs($owner)->get("/environments/{$environment->id}/datastores")->assertInertia(fn (Assert $page) => $page->where('polling', true));
        }

        foreach ([DatastoreStatus::Ready, DatastoreStatus::Failed] as $status) {
            $attachment->update(['status' => $status]);
            $this->actingAs($owner)->get("/environments/{$environment->id}/datastores")->assertInertia(fn (Assert $page) => $page->where('polling', false));
        }
    }

    public function test_the_banner_prop_follows_the_backup_target(): void
    {
        $environment = $this->makeEnvironment();
        BackupTarget::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$environment->id}/datastores")
            ->assertInertia(fn (Assert $page) => $page->where('has_backup_target', true)->where('can.manage_backup_target', false));
    }

    public function test_a_developer_can_view_but_not_change_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::Mysql);
        $developer = User::factory()->create();

        $this->actingAs($developer)->get("/environments/{$environment->id}/datastores")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

        $this->mock(AttachDatastore::class)->shouldNotReceive('handle');
        $this->mock(DetachDatastore::class)->shouldNotReceive('handle');

        $this->actingAs($developer)->post("/environments/{$environment->id}/datastores", ['kind' => 'valkey'])->assertForbidden();
        $this->actingAs($developer)->delete("/datastore-attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_owner_and_admin_attach_through_the_action(): void
    {
        $environment = $this->makeEnvironment();

        foreach ([User::factory()->owner()->create(), User::factory()->admin()->create()] as $user) {
            $this->mock(AttachDatastore::class)
                ->shouldReceive('handle')
                ->once()
                ->withArgs(fn (Environment $given, User $by, DatastoreKind $kind) => $given->is($environment) && $by->is($user) && $kind === DatastoreKind::Rustfs);

            $this->actingAs($user)->post("/environments/{$environment->id}/datastores", ['kind' => 'rustfs'])->assertRedirect();
        }
    }

    public function test_a_developer_attaches_to_an_unprotected_environment(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();

        $this->mock(AttachDatastore::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Environment $given, User $by, DatastoreKind $kind) => $given->is($environment) && $by->is($developer) && $kind === DatastoreKind::Valkey);

        $this->actingAs($developer)->post("/environments/{$environment->id}/datastores", ['kind' => 'valkey'])->assertRedirect();
    }

    public function test_the_kind_is_validated(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->owner()->create())->post("/environments/{$environment->id}/datastores", ['kind' => 'oracle'])->assertSessionHasErrors('kind');
    }

    public function test_the_other_database_refusal_is_shown(): void
    {
        $environment = $this->makeEnvironment();
        $this->attach($environment, DatastoreKind::Mysql);

        $this->actingAs(User::factory()->owner()->create())
            ->post("/environments/{$environment->id}/datastores", ['kind' => 'postgres'])
            ->assertSessionHasErrors(['kind' => 'This environment already has a database. Detach it first.']);

        $this->assertSame(1, DatastoreAttachment::count());
    }

    public function test_the_action_message_is_shown_on_a_refusal(): void
    {
        $environment = $this->makeEnvironment();
        $this->mock(AttachDatastore::class)->shouldReceive('handle')->andThrow(new InvalidArgumentException('Nope.'));

        $this->actingAs(User::factory()->owner()->create())->post("/environments/{$environment->id}/datastores", ['kind' => 'mysql'])->assertSessionHasErrors(['kind' => 'Nope.']);
    }

    public function test_an_admin_detaches_through_the_action(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::Mysql);
        $admin = User::factory()->admin()->create();

        $this->mock(DetachDatastore::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (DatastoreAttachment $given, User $by) => $given->is($attachment) && $by->is($admin));

        $this->actingAs($admin)->delete("/datastore-attachments/{$attachment->id}")->assertRedirect();
    }
}

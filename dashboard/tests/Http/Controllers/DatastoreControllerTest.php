<?php

namespace Tests\Http\Controllers;

use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\DetachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class DatastoreControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private function attach(Environment $environment, DatastoreKind $kind, DatastoreStatus $status = DatastoreStatus::READY, array $attributes = []): DatastoreAttachment
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
        $failed = $this->attach($environment, DatastoreKind::VALKEY, DatastoreStatus::FAILED, ['error' => 'The pod did not start.']);
        EnvVar::factory()->for($environment)->create(['key' => 'REDIS_PASSWORD', 'value' => 'attachment-password-abc', 'source' => EnvVarSource::DATASTORE, 'is_secret' => true, 'datastore_attachment_id' => $failed->id]);
        EnvVar::factory()->for($environment)->create(['key' => 'REDIS_HOST', 'value' => 'valkey.internal.example', 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $failed->id]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('environments/show')
            ->has('datastores.attachments', 1)
            ->where('datastores.attachments.0.kind', 'valkey')
            ->where('datastores.attachments.0.source', 'standard')
            ->where('datastores.attachments.0.label', 'Valkey')
            ->where('datastores.attachments.0.status', 'failed')
            ->where('datastores.attachments.0.error', 'The pod did not start.')
            ->where('datastores.attachments.0.runs_on', 'local')
            ->where('datastores.attachments.0.server.cluster', 'local')
            ->where('datastores.attachments.0.env_keys', ['REDIS_HOST', 'REDIS_PASSWORD'])
            ->where('datastores.attachments.0.detach_refusal', null)
            ->where('datastores.attachments.0.visibility', null)
            ->where('datastores.has_backup_target', false)
            ->where('datastores.polling', false)
            ->where('can.update', true));
        $this->assertSame([
            ['standard', 'mysql', 'MySQL', null],
            ['standard', 'postgres', 'Postgres', null],
            ['standard', 'rustfs', 'RustFS bucket', null],
            ['managed', 'mysql', 'Managed MySQL', 'A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.'],
            ['managed', 'postgres', 'Managed Postgres', 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the database right.'],
            ['managed', 'rustfs', 'Spaces bucket', 'A Spaces bucket needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the spaces_key right.'],
            ['external', 'mysql', 'External MySQL', null],
            ['external', 'postgres', 'External Postgres', null],
            ['external', 'rustfs', 'External S3 bucket', null],
        ], $this->offers($response));

        $raw = $response->getContent();
        foreach (['attachment-password-abc', 'root-password-xyz', 'valkey.internal.example'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_the_inertia_json_response_never_contains_a_secret(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::MYSQL);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'attachment-password-abc', 'source' => EnvVarSource::DATASTORE, 'is_secret' => true, 'datastore_attachment_id' => $attachment->id]);
        BackupTarget::factory()->create(['access_key' => 'AKIATARGETACCESS', 'secret_key' => 'target-secret-key-123']);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get("/environments/{$environment->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
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
        $attachment = $this->attach($environment, DatastoreKind::MYSQL, DatastoreStatus::PENDING);

        foreach ([DatastoreStatus::PENDING, DatastoreStatus::PROVISIONING] as $status) {
            $attachment->update(['status' => $status]);
            $this->actingAs($owner)->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page->where('datastores.polling', true));
        }

        foreach ([DatastoreStatus::READY, DatastoreStatus::FAILED] as $status) {
            $attachment->update(['status' => $status]);
            $this->actingAs($owner)->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page->where('datastores.polling', false));
        }
    }

    public function test_the_banner_prop_follows_the_backup_target(): void
    {
        $environment = $this->makeEnvironment();
        BackupTarget::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('datastores.has_backup_target', true)->where('can.manage_credentials', false));
    }

    public function test_a_developer_can_view_but_not_change_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::MYSQL);
        $developer = User::factory()->create();

        $this->actingAs($developer)->get("/environments/{$environment->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

        $this->mock(AttachDatastore::class)->shouldNotReceive('execute');
        $this->mock(DetachDatastore::class)->shouldNotReceive('execute');

        $this->actingAs($developer)->post("/environments/{$environment->id}/datastores", ['kind' => 'valkey'])->assertForbidden();
        $this->actingAs($developer)->delete("/datastore-attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_owner_and_admin_attach_through_the_action(): void
    {
        $environment = $this->makeEnvironment();

        foreach ([User::factory()->owner()->create(), User::factory()->admin()->create()] as $user) {
            $this->mock(AttachDatastore::class)
                ->shouldReceive('execute')
                ->once()
                ->withArgs(fn (Environment $given, User $by, DatastoreKind $kind) => $given->is($environment) && $by->is($user) && $kind === DatastoreKind::RUSTFS);

            $this->actingAs($user)->post("/environments/{$environment->id}/datastores", ['kind' => 'rustfs'])->assertRedirect();
        }
    }

    public function test_a_developer_attaches_to_an_unprotected_environment(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();

        $this->mock(AttachDatastore::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn (Environment $given, User $by, DatastoreKind $kind) => $given->is($environment) && $by->is($developer) && $kind === DatastoreKind::VALKEY);

        $this->actingAs($developer)->post("/environments/{$environment->id}/datastores", ['kind' => 'valkey'])->assertRedirect();
    }

    public function test_the_kind_is_validated(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->owner()->create())->post("/environments/{$environment->id}/datastores", ['kind' => 'oracle'])->assertSessionHasErrors('kind');
    }

    public function test_a_second_database_without_a_name_is_refused(): void
    {
        $environment = $this->makeEnvironment();
        $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);

        $this->actingAs(User::factory()->owner()->create())
            ->post("/environments/{$environment->id}/datastores", ['kind' => 'postgres'])
            ->assertSessionHasErrors(['kind' => 'This environment already has a database. Give the new database a name.']);

        $this->assertSame(1, DatastoreAttachment::count());
    }

    public function test_the_form_attaches_a_database_with_its_name_and_shows_a_name_refusal(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/environments/{$environment->id}/datastores", ['kind' => 'mysql', 'name' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/environments/{$environment->id}/datastores", ['kind' => 'postgres', 'name' => 'reports'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/environments/{$environment->id}/datastores", ['kind' => 'postgres', 'name' => 'Reports'])
            ->assertSessionHasErrors(['kind' => 'Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.']);
        $this->actingAs($owner)->post("/environments/{$environment->id}/datastores", ['kind' => 'valkey', 'name' => 'cache'])->assertSessionHasNoErrors();

        $this->assertSame(
            [['mysql', 'main', true], ['postgres', 'reports', false], ['valkey', null, false]],
            DatastoreAttachment::query()->with('datastore')->orderBy('id')->get()->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->name, $a->is_default])->all(),
        );
    }

    public function test_make_default_needs_the_right_to_change_the_environment_and_goes_through_the_action(): void
    {
        $environment = $this->makeEnvironment();
        $main = $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);
        $reports = $this->attach($environment, DatastoreKind::POSTGRES, attributes: ['name' => 'reports', 'host' => 'postgres-2.oc-datastores.svc.cluster.local', 'port' => 5432]);
        $valkey = $this->attach($environment, DatastoreKind::VALKEY);

        $this->actingAs(User::factory()->create())->post("/datastore-attachments/{$reports->id}/default")->assertForbidden();
        $this->assertTrue($main->fresh()->is_default);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/datastore-attachments/{$reports->id}/default")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([false, true], [$main->fresh()->is_default, $reports->fresh()->is_default]);
        $this->assertSame(['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'], $reports->envVars()->orderBy('key')->pluck('key')->all());

        $this->actingAs($admin)->post("/datastore-attachments/{$valkey->id}/default")
            ->assertSessionHasErrors(['default' => 'Only a database or a bucket can be the default.']);
    }

    public function test_a_developer_makes_a_database_the_default_on_an_unprotected_environment(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);
        $reports = $this->attach($environment, DatastoreKind::POSTGRES, attributes: ['name' => 'reports']);

        $this->actingAs(User::factory()->create())->post("/datastore-attachments/{$reports->id}/default")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($reports->fresh()->is_default);
    }

    public function test_detaching_the_default_while_another_database_exists_shows_the_refusal(): void
    {
        $environment = $this->makeEnvironment();
        $main = $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);
        $this->attach($environment, DatastoreKind::POSTGRES, attributes: ['name' => 'reports']);

        $this->actingAs(User::factory()->owner()->create())->delete("/datastore-attachments/{$main->id}")
            ->assertSessionHasErrors(['detach' => 'Make another database the default first.']);

        $this->assertSame(2, DatastoreAttachment::count());
    }

    public function test_the_page_shows_each_database_name_the_default_and_the_config_line_of_the_others(): void
    {
        $environment = $this->makeEnvironment();
        $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);
        $this->attach($environment, DatastoreKind::POSTGRES, attributes: ['name' => 'reports']);
        $this->attach($environment, DatastoreKind::VALKEY);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('datastores.attachments.0.name', 'main')
                ->where('datastores.attachments.0.is_default', true)
                ->where('datastores.attachments.0.config_line', null)
                ->where('datastores.attachments.1.name', 'reports')
                ->where('datastores.attachments.1.is_default', false)
                ->where('datastores.attachments.1.config_line', "'reports' => ['driver' => 'pgsql', 'url' => env('REPORTS_DB_URL')],")
                ->where('datastores.attachments.2.name', null)
                ->where('datastores.attachments.2.config_line', null));

        // More databases are always possible. A second key-value store is not, from any source.
        $this->assertSame(
            [['standard', 'mysql'], ['standard', 'postgres'], ['standard', 'rustfs'], ['managed', 'mysql'], ['managed', 'postgres'], ['managed', 'rustfs'], ['external', 'mysql'], ['external', 'postgres'], ['external', 'rustfs']],
            array_map(fn (array $offer) => array_slice($offer, 0, 2), $this->offers($response)),
        );
    }

    public function test_the_menu_offers_a_bucket_again_after_the_first_and_the_page_shows_each_bucket_with_its_disk_line(): void
    {
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();
        $bucketField = fn (TestResponse $response) => collect($this->page($response)['props']['datastores']['offers'])
            ->filter(fn (array $offer) => $offer['family'] === 'bucket')
            ->map(fn (array $offer) => [$offer['source'], $offer['name_field']['value'], $offer['name_field']['description']])->values()->all();
        $before = $bucketField($this->actingAs($owner)->get("/environments/{$environment->id}"));
        $main = $this->attach($environment, DatastoreKind::RUSTFS, attributes: ['name' => 'main', 'is_default' => true]);
        DatastoreAttachment::factory()->create(['datastore_id' => $main->datastore_id, 'environment_id' => $environment->id, 'name' => 'uploads', 'status' => DatastoreStatus::READY]);

        $response = $this->actingAs($owner)->get("/environments/{$environment->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('datastores.attachments.0.family', 'bucket')
                ->where('datastores.attachments.0.label', 'RustFS bucket')
                ->where('datastores.attachments.0.name', 'main')
                ->where('datastores.attachments.0.is_default', true)
                ->where('datastores.attachments.0.config_line', null)
                ->where('datastores.attachments.1.name', 'uploads')
                ->where('datastores.attachments.1.is_default', false)
                ->where('datastores.attachments.1.config_line', "'uploads' => ['driver' => 's3', 'key' => env('UPLOADS_AWS_ACCESS_KEY_ID'), 'secret' => env('UPLOADS_AWS_SECRET_ACCESS_KEY'), 'region' => env('UPLOADS_AWS_DEFAULT_REGION'), 'bucket' => env('UPLOADS_AWS_BUCKET'), 'endpoint' => env('UPLOADS_AWS_ENDPOINT'), 'use_path_style_endpoint' => env('UPLOADS_AWS_USE_PATH_STYLE_ENDPOINT', false)],"));

        $first = 'This is the first bucket of the environment, so it is the default.';
        $later = 'The default bucket keeps its env values. This bucket gets its own env values with its name as a prefix.';
        $this->assertSame([['standard', 'main', $first], ['managed', 'main', $first], ['external', 'main', $first]], $before);
        $this->assertSame([['standard', '', $later], ['managed', '', $later], ['external', '', $later]], $bucketField($response));
        $this->assertSame(
            ['label' => 'Name', 'placeholder' => 'uploads', 'value' => '', 'description' => $later],
            collect($this->page($response)['props']['datastores']['offers'])->firstWhere('family', 'bucket')['name_field'],
        );
        $this->assertSame('main', collect($this->page($response)['props']['datastores']['offers'])->firstWhere('family', 'database')['name_field']['value']);
    }

    public function test_each_offer_tells_the_menu_its_family_its_text_and_the_fields_of_an_address(): void
    {
        $environment = $this->makeEnvironment();
        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")->assertOk();
        $offers = collect($this->page($response)['props']['datastores']['offers']);

        $this->assertSame([
            'kind' => 'valkey',
            'source' => 'standard',
            'family' => 'key_value',
            'name_field' => null,
            'label' => 'Valkey',
            'description' => 'Cache, queues, and sessions',
            'group' => 'In the cluster local',
            'note' => null,
            'refusal' => null,
            'fields' => [],
            'visibility' => null,
        ], $offers->first(fn (array $offer) => $offer['source'] === 'standard' && $offer['kind'] === 'valkey'));
        $this->assertSame([
            'kind' => 'postgres',
            'source' => 'external',
            'family' => 'database',
            'name_field' => [
                'label' => 'Connection name',
                'placeholder' => 'reports',
                'value' => 'main',
                'description' => 'This is the first database of the environment, so it is the default.',
            ],
            'label' => 'External Postgres',
            'description' => 'A SQL database',
            'group' => 'Bring your own',
            'note' => 'Opencloud stores the address and sets the env values. It does not back up the data.',
            'refusal' => null,
            'fields' => [
                ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'value' => null, 'placeholder' => 'db.example.com', 'help' => null],
                ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'value' => 5432, 'placeholder' => null, 'help' => null],
                ['key' => 'database', 'label' => 'Database', 'type' => 'text', 'required' => true, 'value' => null, 'placeholder' => 'shop', 'help' => null],
                ['key' => 'username', 'label' => 'User', 'type' => 'text', 'required' => true, 'value' => null, 'placeholder' => null, 'help' => null],
                ['key' => 'password', 'label' => 'Password', 'type' => 'secret', 'required' => true, 'value' => null, 'placeholder' => null, 'help' => null],
            ],
            'visibility' => null,
        ], $offers->first(fn (array $offer) => $offer['source'] === 'external' && $offer['kind'] === 'postgres'));
        $this->assertSame([
            'standard' => ['refusal' => null],
            'managed' => ['refusal' => 'Opencloud makes a managed resource private.'],
            'external' => ['refusal' => 'An external resource keeps the settings that its owner gave it.'],
        ], $offers->where('family', 'bucket')->mapWithKeys(fn (array $offer) => [$offer['source'] => $offer['visibility']])->all());
        $this->assertSame(
            [
                ['valkey', 'host', 'text'], ['valkey', 'port', 'number'], ['valkey', 'password', 'secret'], ['valkey', 'tls', 'toggle'],
                ['rustfs', 'endpoint', 'text'], ['rustfs', 'region', 'text'], ['rustfs', 'bucket', 'text'], ['rustfs', 'access_key_id', 'text'], ['rustfs', 'password', 'secret'], ['rustfs', 'path_style', 'toggle'],
            ],
            $offers->where('source', 'external')->whereIn('kind', ['valkey', 'rustfs'])
                ->flatMap(fn (array $offer) => array_map(fn (array $field) => [$offer['kind'], $field['key'], $field['type']], $offer['fields']))->values()->all(),
        );
    }

    public function test_the_form_adds_a_public_bucket_and_the_page_shows_its_address(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/environments/{$environment->id}/datastores", ['kind' => 'rustfs', 'name' => 'main', 'public' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $bucket = DatastoreAttachment::sole();
        $this->assertTrue($bucket->is_public);
        $this->actingAs($owner)->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page
            ->where('datastores.attachments.0.visibility', [
                'public' => true,
                'url' => "https://env-{$environment->id}.files.203.0.113.10.sslip.io",
                'refusal' => null,
            ]));

        $this->makeCluster()->update(['ingress_ip' => null]);
        $this->actingAs($owner)->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page
            ->where('datastores.attachments.0.visibility', [
                'public' => true,
                'url' => null,
                'refusal' => 'The cluster has no public IPv4 address, so a bucket on it cannot be public.',
            ]));
    }

    public function test_an_external_bucket_shows_no_visibility_because_its_owner_sets_it(): void
    {
        $environment = $this->makeEnvironment();
        $external = Datastore::factory()->create(['cluster_id' => null, 'kind' => DatastoreKind::RUSTFS, 'source' => DatastoreSource::EXTERNAL]);
        DatastoreAttachment::factory()->create(['datastore_id' => $external->id, 'environment_id' => $environment->id, 'name' => 'main', 'is_default' => true, 'host' => 'https://s3.example.com']);
        $this->attach($environment, DatastoreKind::RUSTFS, attributes: ['name' => 'exports']);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}")->assertInertia(fn (Assert $page) => $page
            ->where('datastores.attachments.0.visibility', null)
            ->where('datastores.attachments.1.visibility.public', false));
    }

    public function test_a_developer_cannot_change_the_visibility_on_a_protected_environment(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        $bucket = $this->attach($environment, DatastoreKind::RUSTFS, attributes: ['name' => 'main', 'is_default' => true]);

        $this->actingAs(User::factory()->create())->put("/datastore-attachments/{$bucket->id}/visibility", ['public' => true])->assertForbidden();

        $this->assertFalse($bucket->fresh()->is_public);
        Queue::assertNothingPushed();
    }

    public function test_an_admin_makes_a_bucket_public_and_sees_a_refusal_for_a_database(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();
        $bucket = $this->attach($environment, DatastoreKind::RUSTFS, attributes: ['name' => 'main', 'is_default' => true]);
        $database = $this->attach($environment, DatastoreKind::MYSQL, attributes: ['name' => 'main', 'is_default' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/datastore-attachments/{$bucket->id}/visibility", ['public' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/datastore-attachments/{$database->id}/visibility", ['public' => true])
            ->assertSessionHasErrors(['public' => 'Only a bucket can be public.']);

        $this->assertSame([true, false], [$bucket->fresh()->is_public, $database->fresh()->is_public]);
    }

    public function test_the_action_message_is_shown_on_a_refusal(): void
    {
        $environment = $this->makeEnvironment();
        $this->mock(AttachDatastore::class)->shouldReceive('execute')->andThrow(new InvalidArgumentException('Nope.'));

        $this->actingAs(User::factory()->owner()->create())->post("/environments/{$environment->id}/datastores", ['kind' => 'mysql'])->assertSessionHasErrors(['kind' => 'Nope.']);
    }

    public function test_an_admin_detaches_through_the_action(): void
    {
        $environment = $this->makeEnvironment();
        $attachment = $this->attach($environment, DatastoreKind::MYSQL);
        $admin = User::factory()->admin()->create();

        $this->mock(DetachDatastore::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn (DatastoreAttachment $given, User $by) => $given->is($attachment) && $by->is($admin));

        $this->actingAs($admin)->delete("/datastore-attachments/{$attachment->id}")->assertRedirect();
    }

    /** @return array<string, mixed> the page as the browser gets it */
    private function page(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')), true);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?string}> source, kind, label, and refusal of each offer */
    private function offers(TestResponse $response): array
    {
        return array_map(
            fn (array $offer) => [$offer['source'], $offer['kind'], $offer['label'], $offer['refusal']],
            $this->page($response)['props']['datastores']['offers'],
        );
    }
}

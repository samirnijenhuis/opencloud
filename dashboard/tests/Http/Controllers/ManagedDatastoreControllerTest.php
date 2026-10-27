<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionManagedDatabase;
use App\Domains\Datastores\Jobs\TearDownManagedDatabase;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagedDatastoreControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_DO_TOKEN_SECRET_managed';

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => self::TOKEN]);
        $cluster = Cluster::factory()->create([
            'kind' => ClusterKind::CREATED,
            'provider' => 'digitalocean',
            'status' => ClusterStatus::READY,
            'region' => 'ams3',
            'provider_cluster_id' => 'c0ffee00-1111',
            'provider_vpc_id' => 'vpc-2222',
        ]);
        $this->environment = Environment::factory()->for(Application::factory()->for($cluster))->create(['is_protected' => true]);
    }

    public function test_only_the_owner_gets_the_managed_offers_on_a_digitalocean_cluster(): void
    {
        $url = "/environments/{$this->environment->id}";
        $mysqlOnly = 'A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.';

        $this->assertSame([
            ['mysql', 'Managed MySQL', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', $mysqlOnly],
            ['postgres', 'Managed Postgres', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', null],
            ['valkey', 'Managed Valkey', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', null],
            ['rustfs', 'Spaces bucket', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', null],
        ], $this->managedOffers(User::factory()->owner()->create(), $url));
        $this->assertSame([
            ['mysql', 'Managed MySQL', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', $mysqlOnly],
            ['postgres', 'Managed Postgres', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', 'Only an owner can add a managed resource.'],
            ['valkey', 'Managed Valkey', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', 'Only an owner can add a managed resource.'],
            ['rustfs', 'Spaces bucket', 'Managed by DigitalOcean', 'DigitalOcean runs it and charges your account for it.', 'Only an owner can add a managed resource.'],
        ], $this->managedOffers(User::factory()->admin()->create(), $url));

        $local = Environment::factory()->for(Application::factory()->for(Cluster::factory()->create(['kind' => ClusterKind::LOCAL])))->create();
        $refusal = 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the database right.';
        $this->assertSame([
            ['mysql', 'Managed MySQL', 'Managed by a cloud provider', 'A cloud provider runs it and charges your account for it.', $mysqlOnly],
            ['postgres', 'Managed Postgres', 'Managed by a cloud provider', 'A cloud provider runs it and charges your account for it.', $refusal],
            ['valkey', 'Managed Valkey', 'Managed by a cloud provider', 'A cloud provider runs it and charges your account for it.', 'Managed Valkey needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the database right.'],
            ['rustfs', 'Spaces bucket', 'Managed by a cloud provider', 'A cloud provider runs it and charges your account for it.', 'A Spaces bucket needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the spaces_key right.'],
        ], $this->managedOffers(User::factory()->owner()->create(), "/environments/{$local->id}"));
    }

    public function test_only_the_owner_attaches_a_managed_database(): void
    {
        $url = "/environments/{$this->environment->id}/datastores";

        $this->actingAs(User::factory()->admin()->create())->post($url, ['kind' => 'postgres', 'source' => 'managed'])->assertForbidden();
        $this->assertSame(0, Datastore::query()->count());

        $this->actingAs(User::factory()->owner()->create())->post($url, ['kind' => 'mysql', 'source' => 'managed'])->assertSessionHasErrors(['kind' => 'A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.']);
        $this->assertSame(0, Datastore::count());
        $this->actingAs(User::factory()->owner()->create())->post($url, ['kind' => 'postgres', 'source' => 'managed'])->assertRedirect();

        $this->assertSame([DatastoreSource::MANAGED, DatastoreKind::POSTGRES], [Datastore::query()->sole()->source, Datastore::query()->sole()->kind]);
        Queue::assertPushed(ProvisionManagedDatabase::class);
    }

    public function test_the_source_is_validated(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post("/environments/{$this->environment->id}/datastores", ['kind' => 'mysql', 'source' => 'aws'])
            ->assertSessionHasErrors('source');
    }

    public function test_destroying_a_managed_database_needs_the_owner_and_the_confirmation(): void
    {
        $attachment = $this->managedAttachment();
        $url = "/datastore-attachments/{$attachment->id}";

        $this->actingAs(User::factory()->admin()->create())->delete($url, ['confirm' => true])->assertForbidden();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->delete($url)->assertSessionHasErrors(['confirm' => 'Confirm that you want to destroy this resource and its data.']);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->delete($url, ['confirm' => true])->assertRedirect();

        $this->assertSame(DatastoreStatus::DESTROYING, $attachment->datastore->fresh()->status);
        Queue::assertPushed(TearDownManagedDatabase::class);
    }

    public function test_the_page_shows_a_managed_row_without_backups_and_never_a_secret(): void
    {
        $attachment = $this->managedAttachment();
        $attachment->datastore->update(['tofu_log' => "2026-10-23T00:00:00Z digitalocean_database_cluster.this: Creation complete\n"]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'DO_GENERATED_PASSWORD_SECRET', 'is_secret' => true, 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $attachment->id]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'value' => 'private-db.example.ondigitalocean.com', 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $attachment->id]);

        $response = $this->actingAs(User::factory()->owner()->create())->get("/environments/{$this->environment->id}")->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('datastores.attachments.0.managed', true)
            ->where('datastores.attachments.0.source', 'managed')
            ->where('datastores.attachments.0.label', 'Managed MySQL')
            ->where('datastores.attachments.0.runs_on', 'DigitalOcean')
            ->where('datastores.attachments.0.detach_refusal', null)
            ->where('datastores.attachments.0.env_keys', ['DB_HOST', 'DB_PASSWORD'])
            ->where('datastores.attachments.0.tofu_log', "2026-10-23T00:00:00Z digitalocean_database_cluster.this: Creation complete\n")
            ->missing('datastores.attachments.0.backups'));

        foreach (['DO_GENERATED_PASSWORD_SECRET', self::TOKEN, 'private-db.example.ondigitalocean.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$this->environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('datastores.attachments.0.detach_refusal', 'Only an owner can destroy a managed resource.'));
    }

    public function test_the_page_polls_while_a_managed_database_is_destroyed(): void
    {
        $attachment = $this->managedAttachment();
        $attachment->datastore->update(['status' => DatastoreStatus::DESTROYING]);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$this->environment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('datastores.polling', true)->where('datastores.attachments.0.server.status', 'destroying'));
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string}> kind, label, group, note, and refusal of each managed offer */
    private function managedOffers(User $user, string $url): array
    {
        $page = json_decode(json_encode($this->actingAs($user)->get($url)->assertOk()->viewData('page')), true);

        return collect($page['props']['datastores']['offers'])->where('source', 'managed')
            ->map(fn (array $offer) => [$offer['kind'], $offer['label'], $offer['group'], $offer['note'], $offer['refusal']])
            ->values()->all();
    }

    private function managedAttachment(): DatastoreAttachment
    {
        $datastore = Datastore::factory()->for($this->environment->application->cluster)->create([
            'kind' => DatastoreKind::MYSQL,
            'source' => DatastoreSource::MANAGED,
            'version' => '8',
            'status' => DatastoreStatus::READY,
        ]);

        return DatastoreAttachment::factory()->create([
            'datastore_id' => $datastore->id,
            'environment_id' => $this->environment->id,
            'database_name' => "env_{$this->environment->id}",
            'username' => "env_{$this->environment->id}",
            'password' => 'DO_GENERATED_PASSWORD_SECRET',
            'status' => DatastoreStatus::READY,
        ]);
    }
}

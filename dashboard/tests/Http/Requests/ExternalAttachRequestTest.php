<?php

namespace Tests\Http\Requests;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The attach form for a resource that the user runs. The request parses the typed address at the boundary and refuses
 * bad input with one plain message per field.
 */
class ExternalAttachRequestTest extends TestCase
{
    use RefreshDatabase;

    private const DATABASE = ['kind' => 'mysql', 'source' => 'external', 'host' => 'db.example.com', 'port' => '3306', 'database' => 'shop', 'username' => 'shop_app', 'password' => ' TYPED-db-secret '];

    private const STORE = ['kind' => 'valkey', 'source' => 'external', 'host' => '10.0.0.7', 'port' => '6380', 'password' => '', 'tls' => true];

    private const BUCKET = ['kind' => 'rustfs', 'source' => 'external', 'endpoint' => 'https://fsn1.your-objectstorage.com/', 'region' => 'fsn1', 'bucket' => 'shop-uploads', 'access_key_id' => 'AKIATYPED', 'password' => 'TYPED-bucket-secret', 'path_style' => '1'];

    private Environment $environment;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeKubectl::install();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $this->environment = Environment::factory()->for(Application::factory()->for($cluster))->create(['is_protected' => true]);
        $this->owner = User::factory()->owner()->create();
    }

    public function test_the_form_attaches_each_family_and_the_page_never_shows_a_typed_secret(): void
    {
        $url = "/environments/{$this->environment->id}/datastores";

        foreach ([self::DATABASE, self::STORE, self::BUCKET] as $input) {
            $this->actingAs($this->owner)->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame([
            ['mysql', 'external', 'main', true, 'db.example.com', 3306, 'shop', 'shop_app', ' TYPED-db-secret ', false, null, false],
            ['valkey', 'external', null, false, '10.0.0.7', 6380, null, null, null, true, null, false],
            ['rustfs', 'external', 'main', true, 'https://fsn1.your-objectstorage.com', null, 'shop-uploads', 'AKIATYPED', 'TYPED-bucket-secret', false, 'fsn1', true],
        ], DatastoreAttachment::query()->with('datastore')->orderBy('id')->get()->map(fn (DatastoreAttachment $a) => [
            $a->datastore->kind->value, $a->datastore->source->value, $a->name, $a->is_default,
            $a->host, $a->port, $a->database_name, $a->username, $a->password, $a->tls, $a->region, $a->path_style,
        ])->all());

        $response = $this->actingAs($this->owner)->get("/environments/{$this->environment->id}")->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('datastores.attachments.0.label', 'External MySQL')
            ->where('datastores.attachments.0.runs_on', 'db.example.com')
            ->where('datastores.attachments.0.server.cluster', null)
            ->where('datastores.attachments.1.label', 'External Redis or Valkey')
            ->where('datastores.attachments.1.runs_on', '10.0.0.7')
            ->where('datastores.attachments.2.label', 'External S3 bucket')
            ->where('datastores.attachments.2.runs_on', 'fsn1.your-objectstorage.com')
            ->missing('datastores.attachments.0.backups'));

        foreach (['TYPED-db-secret', 'TYPED-bucket-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_each_family_names_every_missing_field(): void
    {
        $url = "/environments/{$this->environment->id}/datastores";

        $this->actingAs($this->owner)->post($url, ['kind' => 'postgres', 'source' => 'external'])->assertSessionHasErrors([
            'host' => 'Enter a host name or an IPv4 address without a scheme or a port, for example db.example.com.',
            'port' => 'Enter a port from 1 to 65535.',
            'database' => 'Enter the database name with 1 to 63 letters, digits, underscores, and dashes.',
            'username' => 'Enter the user name without spaces.',
            'password' => 'Enter the password of the user.',
        ]);
        $this->actingAs($this->owner)->post($url, ['kind' => 'valkey', 'source' => 'external'])
            ->assertSessionHasErrors(['host', 'port'])
            ->assertSessionDoesntHaveErrors(['password', 'tls']);
        $this->actingAs($this->owner)->post($url, ['kind' => 'rustfs', 'source' => 'external'])->assertSessionHasErrors([
            'endpoint' => 'Enter the endpoint URL with http:// or https:// and no path, for example https://s3.eu-central-1.amazonaws.com.',
            'region' => 'Enter the region with lowercase letters, digits, and dashes, for example eu-central-1.',
            'bucket' => 'Enter the bucket name with 3 to 63 lowercase letters, digits, dots, and dashes.',
            'access_key_id' => 'Enter the access key ID without spaces.',
            'password' => 'Enter the secret access key.',
        ]);

        $this->assertSame(0, Datastore::query()->count());
    }

    public function test_bad_values_are_refused_with_the_message_of_the_field(): void
    {
        $url = "/environments/{$this->environment->id}/datastores";
        $refused = [
            [self::DATABASE, 'host', 'https://db.example.com'],
            [self::DATABASE, 'host', 'db.example.com:3306'],
            [self::DATABASE, 'host', '::1'],
            [self::DATABASE, 'host', 'db_1.example.com'],
            [self::DATABASE, 'port', '0'],
            [self::DATABASE, 'port', '65536'],
            [self::DATABASE, 'port', 'mysql'],
            [self::DATABASE, 'database', 'shop/1'],
            [self::DATABASE, 'username', 'shop app'],
            [self::STORE, 'tls', 'maybe'],
            [self::BUCKET, 'endpoint', 'ftp://files.example.com'],
            [self::BUCKET, 'endpoint', 'https://s3.example.com/shop-uploads'],
            [self::BUCKET, 'endpoint', 's3.example.com'],
            [self::BUCKET, 'bucket', 'Shop_Uploads'],
            [self::BUCKET, 'bucket', 'ab'],
            [self::BUCKET, 'region', 'EU Central'],
        ];

        foreach ($refused as [$input, $field, $value]) {
            $this->actingAs($this->owner)->post($url, [...$input, $field => $value])->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Datastore::query()->count());
    }

    public function test_a_refused_form_keeps_no_secret_in_the_session(): void
    {
        $this->actingAs($this->owner)->post("/environments/{$this->environment->id}/datastores", [...self::BUCKET, 'bucket' => 'X'])
            ->assertSessionHasErrors('bucket');

        $this->assertSame(['AKIATYPED', null], [session()->getOldInput('access_key_id'), session()->getOldInput('password')]);
    }

    public function test_another_source_ignores_the_address_fields(): void
    {
        $this->actingAs($this->owner)->post("/environments/{$this->environment->id}/datastores", [...self::DATABASE, 'source' => 'standard'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $attachment = DatastoreAttachment::query()->with('datastore')->sole();
        $this->assertSame([DatastoreSource::STANDARD, 'env_'.$this->environment->id], [$attachment->datastore->source, $attachment->database_name]);
        $this->assertNotSame(' TYPED-db-secret ', $attachment->password);
    }

    public function test_a_developer_brings_a_resource_only_to_an_environment_that_they_can_change(): void
    {
        $developer = User::factory()->create();
        $staging = Environment::factory()->for($this->environment->application)->create(['name' => 'staging', 'is_protected' => false]);

        $this->actingAs($developer)->post("/environments/{$this->environment->id}/datastores", self::DATABASE)->assertForbidden();
        $this->actingAs($developer)->post("/environments/{$staging->id}/datastores", self::DATABASE)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$staging->id], DatastoreAttachment::query()->pluck('environment_id')->all());
    }
}

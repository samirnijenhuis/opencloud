<?php

namespace Tests\Domains\Datastores\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\SetBucketVisibility;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SetBucketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private Cluster $cluster;

    private User $owner;

    private DatastoreAttachment $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kubectl = FakeKubectl::install();
        $this->cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.5']);
        $environment = Environment::factory()->for(Application::factory()->for($this->cluster))->create();
        $this->owner = User::factory()->owner()->create();
        $this->bucket = app(AttachDatastore::class)->execute($environment, $this->owner, DatastoreKind::RUSTFS);
        AuditEvent::query()->delete();
        $this->kubectl->calls = [];
    }

    public function test_a_public_bucket_gets_the_read_policy_its_url_value_and_a_caddy_sync(): void
    {
        Queue::fake();
        $name = $this->bucket->database_name;

        app(SetBucketVisibility::class)->execute($this->bucket, $this->owner, true);
        $this->provisionAgain();

        $this->assertTrue($this->bucket->fresh()->is_public);
        $this->assertSame("https://{$name}.files.203.0.113.5.sslip.io", $this->bucket->envVars()->where('key', 'AWS_URL')->value('value'));
        $this->assertStringEndsWith("rc bucket anonymous set-json /tmp/{$name}-read.json local/{$name} >/dev/null", $this->lastScript());
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $job) => $job->cluster->is($this->cluster));
        $this->assertSame(
            [['datastore.visibility_changed', ['environment_id' => $this->bucket->environment_id, 'name' => 'main', 'public' => true]]],
            AuditEvent::query()->get()->map(fn (AuditEvent $event) => [$event->action, $event->context])->all(),
        );
    }

    public function test_a_bucket_that_becomes_private_loses_its_url_value_and_its_read_policy(): void
    {
        Queue::fake();
        $this->bucket->update(['is_public' => true]);
        AttachmentEnv::write($this->bucket);
        $this->assertNotNull($this->bucket->envVars()->where('key', 'AWS_URL')->value('value'));
        $name = $this->bucket->database_name;

        app(SetBucketVisibility::class)->execute($this->bucket, $this->owner, false);
        $this->provisionAgain();

        $this->assertFalse($this->bucket->fresh()->is_public);
        $this->assertNull($this->bucket->envVars()->where('key', 'AWS_URL')->value('value'));
        $this->assertStringEndsWith("rc bucket anonymous set private local/{$name} >/dev/null", $this->lastScript());
        Queue::assertPushed(SyncCaddy::class);
    }

    public function test_a_bucket_on_a_cluster_without_an_ipv4_address_stays_private(): void
    {
        Queue::fake();
        $this->cluster->update(['ingress_ip' => null]);

        try {
            app(SetBucketVisibility::class)->execute($this->bucket->fresh(), $this->owner, true);
            $this->fail('The bucket became public.');
        } catch (ValidationException $e) {
            $this->assertSame(['public' => ['The cluster has no public IPv4 address, so a bucket on it cannot be public.']], $e->errors());
        }

        $this->assertFalse($this->bucket->fresh()->is_public);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_the_same_visibility_changes_nothing(): void
    {
        Queue::fake();

        app(SetBucketVisibility::class)->execute($this->bucket, $this->owner, false);

        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();

        app(SetBucketVisibility::class)->execute($this->bucket, $this->owner, true);
        app(SetBucketVisibility::class)->execute($this->bucket, $this->owner, true);

        $this->assertSame(['datastore.visibility_changed'], AuditEvent::query()->pluck('action')->all());
        Queue::assertPushed(ProvisionDatastoreAttachment::class, 1);
    }

    /** Runs the provision job that the action queued, like the worker does. */
    private function provisionAgain(): void
    {
        Queue::pushed(ProvisionDatastoreAttachment::class)->sole()->handle();
    }

    private function lastScript(): string
    {
        $args = $this->kubectl->callsStartingWith('exec')[0]['args'];

        return $args[array_key_last($args)];
    }
}

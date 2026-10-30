<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Jobs\TearDownManagedDatabase;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\CreateEnvironment;
use App\Domains\Environments\Actions\DeleteEnvironment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DeleteEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private User $admin;

    private Application $application;

    private Environment $staging;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class, ProvisionDatastoreAttachment::class, CheckDomain::class, SyncCaddy::class, TearDownManagedDatabase::class]);
        $this->kubectl = FakeKubectl::install();
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/main\n";
        $this->admin = User::factory()->admin()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);

        $this->application = app(CreateApplication::class)->execute($this->admin, ['name' => 'Shop', 'repository' => 'acme/shop', 'cluster_id' => $cluster->id]);
        $production = $this->application->environments()->where('name', 'production')->sole();
        app(AttachDatastore::class)->execute($production, $this->admin, DatastoreKind::VALKEY);
        $this->staging = app(CreateEnvironment::class)->execute($this->application, $this->admin, 'staging', 'staging', $production);
        Domain::factory()->for($this->staging)->create(['hostname' => 'staging.example.com']);
        $this->staging->deployments()->sole()->update(['status' => DeploymentStatus::LIVE]);

        $this->kubectl->calls = [];
        AuditEvent::query()->delete();
    }

    public function test_deleting_an_environment_removes_its_namespace_the_data_of_its_resources_its_domains_and_its_records(): void
    {
        $production = $this->application->environments()->where('name', 'production')->sole();
        $productionAttachments = $production->datastoreAttachments()->orderBy('id')->get(['id', 'database_name'])->toArray();
        [$mysql, $valkey] = $this->staging->datastoreAttachments()->orderBy('id')->get()->all();
        $id = $this->staging->id;

        app(DeleteEnvironment::class)->execute($this->staging, $this->admin);

        $this->assertSame([
            ['delete', 'namespace', "oc-env-{$id}", '--ignore-not-found', '--wait=false'],
            ['exec', '-i', "mysql-{$mysql->datastore_id}-0", '--namespace=oc-datastores', '--container=mysql', '--', 'bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'],
            ['delete', 'deployment', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'service', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'secret', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'persistentvolumeclaim', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame("DROP USER IF EXISTS 'env_{$id}'@'%';\nDROP DATABASE IF EXISTS `env_{$id}`;\n", $this->kubectl->calls[1]['stdin']);

        $this->assertNull(Environment::find($id));
        $this->assertSame([0, 0, 0, 0, 0], [
            Process::where('environment_id', $id)->count(),
            EnvVar::where('environment_id', $id)->count(),
            DatastoreAttachment::where('environment_id', $id)->count(),
            Domain::where('environment_id', $id)->count(),
            Deployment::where('environment_id', $id)->count(),
        ]);
        $this->assertSame($productionAttachments, $production->datastoreAttachments()->orderBy('id')->get(['id', 'database_name'])->toArray());
        Queue::assertPushed(SyncCaddy::class);

        $this->assertSame(
            ['domain.removed', 'domain.removed', 'datastore.destroyed', 'datastore.destroyed', 'environment.deleted'],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(
            ['environment_id' => $id, 'name' => 'staging', 'namespace' => "oc-env-{$id}"],
            AuditEvent::where('action', 'environment.deleted')->sole()->context,
        );
        $this->assertSame([$this->admin->id], AuditEvent::query()->distinct()->pluck('user_id')->all());
    }

    public function test_an_environment_with_a_deployment_that_waits_builds_or_rolls_out_stays(): void
    {
        $deployment = $this->staging->deployments()->sole();

        foreach ([DeploymentStatus::QUEUED, DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING] as $status) {
            $deployment->update(['status' => $status]);

            try {
                app(DeleteEnvironment::class)->execute($this->staging, $this->admin);
                $this->fail("The environment was deleted while its deployment was {$status->value}.");
            } catch (ValidationException $e) {
                $this->assertSame(['environment' => ['A deployment of staging runs. Wait until it ends, then delete the environment.']], $e->errors());
            }
        }

        $this->assertSame([], $this->kubectl->calls);
        $this->assertNotNull(Environment::find($this->staging->id));
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_an_environment_with_a_managed_resource_stays_until_opentofu_destroys_the_resource(): void
    {
        $managed = Datastore::factory()->create(['cluster_id' => $this->application->cluster_id, 'kind' => DatastoreKind::POSTGRES, 'source' => DatastoreSource::MANAGED, 'status' => DatastoreStatus::READY]);
        DatastoreAttachment::factory()->create(['datastore_id' => $managed->id, 'environment_id' => $this->staging->id, 'name' => 'reports', 'is_default' => false]);
        $refusal = ['environment' => ['Opencloud now destroys the managed resources of staging. Delete the environment again when they are gone.']];

        foreach ([1, 2] as $attempt) {
            try {
                app(DeleteEnvironment::class)->execute($this->staging, $this->admin);
                $this->fail("Delete {$attempt} removed the environment before OpenTofu destroyed its resource.");
            } catch (ValidationException $e) {
                $this->assertSame($refusal, $e->errors());
            }
        }

        // The second delete does not start a second destroy.
        Queue::assertPushed(TearDownManagedDatabase::class, 1);
        $this->assertSame([DatastoreStatus::DESTROYING], $this->staging->datastoreAttachments()->with('datastore')->get()->pluck('datastore.status')->all());
        $this->assertSame(0, $this->staging->domains()->count());

        // TearDownManagedDatabase removes the attachment and the datastore when the destroy succeeds.
        $managed->attachments()->delete();
        $managed->delete();
        app(DeleteEnvironment::class)->execute($this->staging, $this->admin);

        $this->assertNull(Environment::find($this->staging->id));
        $this->assertCount(3, $this->kubectl->callsStartingWith('delete namespace'));
    }

    public function test_a_namespace_that_kubectl_cannot_delete_keeps_the_environment_and_its_data(): void
    {
        $this->kubectl->respond('delete namespace', new KubectlResult(1, '', 'Unable to connect to the server'));

        try {
            app(DeleteEnvironment::class)->execute($this->staging, $this->admin);
            $this->fail('The environment was deleted without its namespace.');
        } catch (ValidationException $e) {
            $this->assertStringStartsWith('Opencloud could not delete the namespace. ', $e->errors()['environment'][0]);
        }

        $this->assertCount(1, $this->kubectl->calls);
        $this->assertSame(2, $this->staging->datastoreAttachments()->count());
        $this->assertSame(2, $this->staging->domains()->count());
        $this->assertSame(0, AuditEvent::count());
    }
}

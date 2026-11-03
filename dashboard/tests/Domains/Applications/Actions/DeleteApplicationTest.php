<?php

namespace Tests\Domains\Applications\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Actions\DeleteApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\CreateEnvironment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DeleteApplicationTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private User $admin;

    private Application $application;

    private Environment $production;

    private Environment $staging;

    private Environment $template;

    private Environment $preview;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class, ProvisionDatastoreAttachment::class, CheckDomain::class, SyncCaddy::class]);
        $this->kubectl = FakeKubectl::install();
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/main\n";
        $this->admin = User::factory()->admin()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);

        $this->application = app(CreateApplication::class)->execute($this->admin, ['name' => 'Shop', 'repository' => 'acme/shop', 'cluster_id' => $cluster->id]);
        $this->production = $this->application->environments()->where('name', 'production')->sole();
        $this->template = $this->application->environments()->where('kind', EnvironmentKind::PREVIEW_TEMPLATE)->sole();
        app(AttachDatastore::class)->execute($this->production, $this->admin, DatastoreKind::VALKEY);
        $this->staging = app(CreateEnvironment::class)->execute($this->application, $this->admin, 'staging', 'staging', $this->production);
        $this->preview = Environment::factory()->for($this->application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12, 'is_protected' => false]);
        Domain::factory()->for($this->preview)->create(['hostname' => 'pr-12.example.com']);

        foreach ([$this->production, $this->staging, $this->preview] as $environment) {
            Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::LIVE]);
        }

        $this->kubectl->calls = [];
        AuditEvent::query()->delete();
    }

    public function test_deleting_an_application_deletes_the_previews_then_the_permanent_environments_then_the_template_then_the_application(): void
    {
        $ids = [$this->preview->id, $this->production->id, $this->staging->id, $this->template->id];

        app(DeleteApplication::class)->execute($this->application, $this->admin);

        $this->assertSame(
            array_map(fn (int $id) => ['delete', 'namespace', "oc-env-{$id}", '--ignore-not-found', '--wait=false'], $ids),
            array_column($this->kubectl->callsStartingWith('delete namespace'), 'args'),
        );
        $this->assertNull(Application::find($this->application->id));
        $this->assertSame([0, 0, 0, 0, 0, 0], [
            Environment::count(),
            Process::count(),
            EnvVar::count(),
            DatastoreAttachment::count(),
            Domain::count(),
            Deployment::count(),
        ]);

        $deleted = AuditEvent::where('action', 'environment.deleted')->orderBy('id')->get();
        $this->assertSame($ids, $deleted->pluck('context.environment_id')->all());
        $event = AuditEvent::query()->latest('id')->first();
        $this->assertSame(
            ['application.deleted', ['name' => 'Shop', 'repository' => 'acme/shop'], $this->admin->id, 'application', $this->application->id],
            [$event->action, $event->context, $event->user_id, $event->subject_type, $event->subject_id],
        );
    }

    public function test_a_refused_environment_delete_keeps_the_application_and_a_second_delete_finishes_the_job(): void
    {
        $deployment = $this->production->deployments()->sole();
        $deployment->update(['status' => DeploymentStatus::DEPLOYING]);
        $this->application->update(['previews_enabled' => true]);

        try {
            app(DeleteApplication::class)->execute($this->application, $this->admin);
            $this->fail('The application was deleted while a deployment of production ran.');
        } catch (ValidationException $e) {
            $this->assertSame(['environment' => ['A deployment of production runs. Wait until it ends, then delete the environment.']], $e->errors());
        }

        // The preview went first. Production and the rest stay until the deployment ends.
        $this->assertNull(Environment::find($this->preview->id));
        $this->assertSame([$this->production->id, $this->template->id, $this->staging->id], $this->application->environments()->orderBy('id')->pluck('id')->all());
        $this->assertNotNull(Application::find($this->application->id));
        $this->assertFalse(Application::find($this->application->id)->previews_enabled, 'A pull request must open no new preview during the delete.');
        $this->assertSame(0, AuditEvent::where('action', 'application.deleted')->count());

        $deployment->update(['status' => DeploymentStatus::LIVE]);
        app(DeleteApplication::class)->execute($this->application, $this->admin);

        $this->assertNull(Application::find($this->application->id));
        $this->assertSame(0, Environment::count());
    }
}

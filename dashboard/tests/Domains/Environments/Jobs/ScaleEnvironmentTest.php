<?php

namespace Tests\Domains\Environments\Jobs;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class ScaleEnvironmentTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private Environment $environment;

    private DatastoreAttachment $valkey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kubectl = FakeKubectl::install();
        // Web autoscales from 2 to 4 pods. The worker has a fixed count. The Deployment "old" belongs to a removed process.
        $this->environment = $this->makeEnvironment(attributes: ['min_instances' => 2, 'max_instances' => 4, 'cpu_target' => 60, 'sleep_after_minutes' => 15]);
        Process::factory()->for($this->environment)->create(['name' => 'worker', 'min_instances' => 3, 'max_instances' => 3]);
        Process::factory()->for($this->environment)->scheduled()->create(['name' => 'cleanup']);
        $this->kubectl->respond('get deployments', new KubectlResult(0, json_encode(['items' => [
            ['metadata' => ['name' => 'web']], ['metadata' => ['name' => 'worker']], ['metadata' => ['name' => 'old']],
        ]])));
        $this->kubectl->respond('get cronjobs', new KubectlResult(0, json_encode(['items' => [['metadata' => ['name' => 'cleanup']]]])));

        $this->valkey = $this->attachment(DatastoreKind::VALKEY, DatastoreSource::STANDARD);
        // A shared MySQL server and a managed Valkey keep running.
        $this->attachment(DatastoreKind::MYSQL, DatastoreSource::STANDARD);
        $this->attachment(DatastoreKind::VALKEY, DatastoreSource::MANAGED);
    }

    public function test_a_sleeping_environment_loses_its_autoscalers_and_every_pod(): void
    {
        $this->environment->forceFill(['slept_at' => now()])->save();

        ScaleEnvironment::dispatch($this->environment);

        $id = $this->environment->id;
        $namespace = "--namespace=oc-env-{$id}";
        $this->assertSame([
            ['delete', 'horizontalpodautoscaler', "--selector=opencloud.dev/environment={$id},opencloud.dev/process", $namespace, '--ignore-not-found', '--wait=false'],
            ['get', 'deployments', "--selector=opencloud.dev/environment={$id}", $namespace, '--ignore-not-found', '-o', 'json'],
            ['scale', 'deployment/web', '--replicas=0', $namespace],
            ['scale', 'deployment/worker', '--replicas=0', $namespace],
            ['scale', 'deployment/old', '--replicas=0', $namespace],
            ['get', 'cronjobs', "--selector=opencloud.dev/environment={$id}", $namespace, '--ignore-not-found', '-o', 'json'],
            ['patch', 'cronjob/cleanup', $namespace, '--type=merge', '--patch={"spec":{"suspend":true}}'],
            ['scale', "deployment/valkey-{$this->valkey->id}", '--replicas=0', '--namespace=oc-datastores'],
        ], array_column($this->kubectl->calls, 'args'));
    }

    public function test_a_woken_environment_starts_at_its_minimum_and_gets_its_autoscaler_back(): void
    {
        ScaleEnvironment::dispatch($this->environment);

        $id = $this->environment->id;
        $namespace = "--namespace=oc-env-{$id}";
        $this->assertSame([
            // Valkey starts before the pods that use it.
            ['scale', "deployment/valkey-{$this->valkey->id}", '--replicas=1', '--namespace=oc-datastores'],
            ['get', 'deployments', "--selector=opencloud.dev/environment={$id}", $namespace, '--ignore-not-found', '-o', 'json'],
            ['scale', 'deployment/web', '--replicas=2', $namespace],
            ['scale', 'deployment/worker', '--replicas=3', $namespace],
            // The removed process stays stopped until the next deploy deletes its Deployment.
            ['scale', 'deployment/old', '--replicas=0', $namespace],
            ['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'],
            ['get', 'cronjobs', "--selector=opencloud.dev/environment={$id}", $namespace, '--ignore-not-found', '-o', 'json'],
            ['patch', 'cronjob/cleanup', $namespace, '--type=merge', '--patch={"spec":{"suspend":false}}'],
        ], array_column($this->kubectl->calls, 'args'));

        $autoscaler = $this->kubectl->appliedManifests()[0];
        $this->assertSame(
            ['HorizontalPodAutoscaler', 'web', 2, 4, 60],
            [$autoscaler['kind'], $autoscaler['metadata']['name'], $autoscaler['spec']['minReplicas'], $autoscaler['spec']['maxReplicas'], $autoscaler['spec']['metrics'][0]['resource']['target']['averageUtilization']],
        );
    }

    private function attachment(DatastoreKind $kind, DatastoreSource $source): DatastoreAttachment
    {
        $datastore = Datastore::factory()->create(['cluster_id' => $this->environment->application->cluster_id, 'kind' => $kind, 'source' => $source]);

        return DatastoreAttachment::factory()->for($datastore)->for($this->environment)->create(['status' => DatastoreStatus::READY]);
    }
}

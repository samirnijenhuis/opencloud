<?php

namespace Tests\Domains\Environments;

use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class LiveStatusTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const NOT_FINISHED = '--field-selector=status.phase!=Succeeded,status.phase!=Failed';

    private FakeKubectl $kubectl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $this->kubectl = FakeKubectl::install();
    }

    public function test_the_status_counts_instances_restarts_usage_and_requests_per_process(): void
    {
        $environment = $this->makeEnvironment();
        $namespace = "oc-env-{$environment->id}";
        $this->kubectl->respond('get deployments', $this->items([
            $this->deployment('worker', replicas: 1, ready: null),
            $this->deployment('web', replicas: 2, ready: 2),
            ['metadata' => ['name' => 'something-else', 'labels' => []], 'spec' => ['replicas' => 1], 'status' => []],
        ]));
        $this->kubectl->respond('get cronjobs', $this->items([]));
        $this->kubectl->respond('get pods', $this->items([
            $this->pod('web-a', 'web', restarts: 1, cpu: '500m', memory: '512Mi'),
            $this->pod('web-b', 'web', restarts: 2, cpu: '500m', memory: '512Mi'),
            $this->pod('worker-a', 'worker', restarts: 0, cpu: '250m', memory: '256Mi'),
            $this->pod('cmd-4-x', 'command', restarts: 0, cpu: '1', memory: '1Gi'),
        ]));
        $this->kubectl->respond('get --raw', new KubectlResult(0, json_encode(['items' => [
            ['metadata' => ['name' => 'web-a'], 'containers' => [['name' => 'web', 'usage' => ['cpu' => '2500000n', 'memory' => '81920Ki']]]],
            ['metadata' => ['name' => 'web-b'], 'containers' => [['name' => 'web', 'usage' => ['cpu' => '12m', 'memory' => '100Mi']]]],
        ]])));

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/status")->assertOk()->assertExactJson([
            'state' => 'ok',
            'error' => null,
            'processes' => [
                ['name' => 'web', 'desired' => 2, 'ready' => 2, 'restarts' => 3, 'cpu_millicores' => 15, 'memory_mib' => 180],
                ['name' => 'worker', 'desired' => 1, 'ready' => 0, 'restarts' => 0, 'cpu_millicores' => null, 'memory_mib' => null],
            ],
            'scheduled' => [],
            'requests' => ['cpu_millicores' => 2250, 'memory_mib' => 2304],
            'checked_at' => '2026-10-24T12:00:00+00:00',
        ]);
        $this->assertSame([
            ['get', 'deployments', "--namespace={$namespace}", '-o', 'json'],
            ['get', 'cronjobs', "--namespace={$namespace}", '-o', 'json'],
            ['get', 'pods', "--namespace={$namespace}", self::NOT_FINISHED, '-o', 'json'],
            ['get', '--raw', "/apis/metrics.k8s.io/v1beta1/namespaces/{$namespace}/pods"],
        ], array_column($this->kubectl->calls, 'args'));
    }

    public function test_missing_metrics_give_null_usage_and_the_answer_stays_cached_for_10_seconds(): void
    {
        $environment = $this->makeEnvironment();
        $this->kubectl->respond('get deployments', $this->items([$this->deployment('web', replicas: 1, ready: 1)]));
        $this->kubectl->respond('get cronjobs', $this->items([]));
        $this->kubectl->respond('get pods', $this->items([$this->pod('web-a', 'web', restarts: 0, cpu: '500m', memory: '512Mi')]));
        $this->kubectl->respond('get --raw', new KubectlResult(1, '', 'Error from server (NotFound): the server could not find the requested resource'));
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson("/environments/{$environment->id}/status")->json();
        $this->travel(9)->seconds();
        $this->actingAs($user)->getJson("/environments/{$environment->id}/status")->assertExactJson($first);
        $this->assertCount(4, $this->kubectl->calls);
        $this->travel(2)->seconds();
        $this->actingAs($user)->getJson("/environments/{$environment->id}/status");

        $this->assertSame(['state' => 'ok', 'name' => 'web', 'cpu_millicores' => null, 'memory_mib' => null], [
            'state' => $first['state'], 'name' => $first['processes'][0]['name'], 'cpu_millicores' => $first['processes'][0]['cpu_millicores'], 'memory_mib' => $first['processes'][0]['memory_mib'],
        ]);
        $this->assertCount(8, $this->kubectl->calls);
    }

    public function test_each_scheduled_process_reports_its_last_run_and_whether_that_run_failed(): void
    {
        $environment = $this->makeEnvironment();
        $this->kubectl->respond('get deployments', $this->items([$this->deployment('web', replicas: 1, ready: 1)]));
        $this->kubectl->respond('get cronjobs', $this->items([
            $this->cronJob('scheduler', ['lastScheduleTime' => '2026-10-24T11:59:00Z', 'lastSuccessfulTime' => '2026-10-24T11:59:02Z']),
            $this->cronJob('report', ['lastScheduleTime' => '2026-10-24T11:00:00Z', 'lastSuccessfulTime' => '2026-10-23T11:00:04Z']),
            $this->cronJob('import', ['lastScheduleTime' => '2026-10-24T11:59:00Z', 'lastSuccessfulTime' => '2026-10-24T11:58:03Z', 'active' => [['name' => 'import-29000000']]]),
            $this->cronJob('nightly', []),
            ['metadata' => ['name' => 'backup', 'labels' => []], 'status' => ['lastScheduleTime' => '2026-10-24T03:00:00Z']],
        ]));
        $this->kubectl->respond('get pods', $this->items([]));
        $this->kubectl->respond('get --raw', $this->items([]));

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/status")->assertOk()->assertJsonPath('scheduled', [
            ['name' => 'import', 'last_run_at' => '2026-10-24T11:59:00Z', 'last_run_failed' => false],
            ['name' => 'nightly', 'last_run_at' => null, 'last_run_failed' => false],
            ['name' => 'report', 'last_run_at' => '2026-10-24T11:00:00Z', 'last_run_failed' => true],
            ['name' => 'scheduler', 'last_run_at' => '2026-10-24T11:59:00Z', 'last_run_failed' => false],
        ]);
    }

    public function test_a_cluster_that_does_not_answer_gives_the_unknown_state(): void
    {
        $environment = $this->makeEnvironment();
        $this->kubectl->respond('get', new KubectlResult(1, '', 'Unable to connect to the server: dial tcp 10.0.0.1:6443: i/o timeout'));
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/environments/{$environment->id}/status")->assertOk()->assertExactJson([
            'state' => 'unknown',
            'error' => 'Opencloud could not read the status from the cluster.',
            'processes' => [],
            'scheduled' => [],
            'requests' => null,
            'checked_at' => '2026-10-24T12:00:00+00:00',
        ]);
        $this->actingAs($user)->getJson("/clusters/{$this->cluster->id}/capacity")->assertOk()->assertExactJson([
            'state' => 'unknown',
            'error' => 'Opencloud could not read the status from the cluster.',
            'nodes' => null,
            'allocatable' => null,
            'requested' => null,
            'checked_at' => '2026-10-24T12:00:00+00:00',
        ]);
    }

    public function test_the_capacity_sums_the_allocatable_resources_of_the_nodes_and_the_requests_of_all_pods(): void
    {
        $cluster = $this->makeCluster();
        $this->kubectl->respond('get nodes', $this->items([
            ['metadata' => ['name' => 'node-1'], 'status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi', 'pods' => '110']]],
            ['metadata' => ['name' => 'node-2'], 'status' => ['allocatable' => ['cpu' => '3800m', 'memory' => '16422380Ki', 'pods' => '110']]],
        ]));
        $this->kubectl->respond('get pods', $this->items([
            $this->pod('web-a', 'web', restarts: 0, cpu: '500m', memory: '512Mi'),
            ['metadata' => ['name' => 'coredns-1', 'labels' => []], 'spec' => ['containers' => [['name' => 'coredns', 'resources' => ['requests' => ['cpu' => '100m', 'memory' => '70Mi']]]]], 'status' => []],
            ['metadata' => ['name' => 'no-requests', 'labels' => []], 'spec' => ['containers' => [['name' => 'app', 'resources' => []], ['name' => 'sidecar']]], 'status' => []],
        ]));

        $this->actingAs(User::factory()->create())->getJson("/clusters/{$cluster->id}/capacity")->assertOk()->assertExactJson([
            'state' => 'ok',
            'error' => null,
            'nodes' => 2,
            'allocatable' => ['cpu_millicores' => 7800, 'memory_mib' => 24229],
            'requested' => ['cpu_millicores' => 600, 'memory_mib' => 582],
            'checked_at' => '2026-10-24T12:00:00+00:00',
        ]);
        $this->assertSame([
            ['get', 'nodes', '-o', 'json'],
            ['get', 'pods', '--all-namespaces', self::NOT_FINISHED, '-o', 'json'],
        ], array_column($this->kubectl->calls, 'args'));
    }

    public function test_guests_get_no_status(): void
    {
        $environment = $this->makeEnvironment();

        $this->getJson("/environments/{$environment->id}/status")->assertUnauthorized();
        $this->getJson("/clusters/{$this->cluster->id}/capacity")->assertUnauthorized();
        $this->assertSame([], $this->kubectl->calls);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function items(array $items): KubectlResult
    {
        return new KubectlResult(0, json_encode(['apiVersion' => 'v1', 'kind' => 'List', 'items' => $items]));
    }

    /** @return array<string, mixed> */
    private function deployment(string $process, int $replicas, ?int $ready): array
    {
        return [
            'metadata' => ['name' => $process, 'labels' => ['opencloud.dev/process' => $process]],
            'spec' => ['replicas' => $replicas],
            'status' => $ready === null ? ['replicas' => $replicas] : ['replicas' => $replicas, 'readyReplicas' => $ready],
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function cronJob(string $process, array $status): array
    {
        return ['metadata' => ['name' => $process, 'labels' => ['opencloud.dev/process' => $process]], 'spec' => ['schedule' => '* * * * *'], 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function pod(string $name, string $process, int $restarts, string $cpu, string $memory): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => ['opencloud.dev/process' => $process]],
            'spec' => ['containers' => [['name' => $process, 'resources' => ['requests' => ['cpu' => $cpu, 'memory' => $memory], 'limits' => ['cpu' => $cpu, 'memory' => $memory]]]]],
            'status' => ['phase' => 'Running', 'containerStatuses' => [['name' => $process, 'restartCount' => $restarts]]],
        ];
    }
}

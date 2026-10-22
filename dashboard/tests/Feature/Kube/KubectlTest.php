<?php

namespace Tests\Feature\Kube;

use App\Enums\ClusterKind;
use App\Kube\FakeKubectl;
use App\Kube\Kubectl;
use App\Kube\KubectlException;
use App\Kube\KubectlResult;
use App\Models\Cluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KubectlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_connected_cluster_gets_a_private_kubeconfig_file_that_is_removed_after_the_call(): void
    {
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Connected, 'name' => 'do-ams3', 'kubeconfig' => "apiVersion: v1\nkind: Config\n"]);
        $seen = [];

        $kubectl = new class($cluster, $seen) extends Kubectl
        {
            public function __construct(Cluster $cluster, private array &$seen)
            {
                parent::__construct($cluster);
            }

            protected function process(array $command, ?string $stdin, int $timeout): KubectlResult
            {
                $path = substr($command[1], strlen('--kubeconfig='));
                $this->seen = [
                    'command' => $command,
                    'contents' => file_get_contents($path),
                    'mode' => fileperms($path) & 0777,
                    'path' => $path,
                ];

                return new KubectlResult(0, 'ok');
            }
        };

        $kubectl->run(['get', 'nodes']);

        $this->assertSame(['kubectl', '--kubeconfig='.$seen['path'], 'get', 'nodes'], $seen['command']);
        $this->assertSame("apiVersion: v1\nkind: Config\n", $seen['contents']);
        $this->assertSame(0600, $seen['mode']);
        $this->assertFileDoesNotExist($seen['path']);
    }

    public function test_the_local_cluster_uses_the_in_cluster_config(): void
    {
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);

        Kubectl::for($local)->get('nodes');

        $this->assertSame([['cluster' => 'local', 'args' => ['get', 'nodes', '--ignore-not-found', '-o', 'json'], 'stdin' => null]], $fake->calls);
    }

    public function test_apply_sends_one_list_with_server_side_apply(): void
    {
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);

        Kubectl::for($local)->apply([
            ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'oc-env-1']],
        ]);

        $call = $fake->callsStartingWith('apply')[0];
        $this->assertSame(['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'], $call['args']);
        $this->assertSame('{"apiVersion":"v1","kind":"List","items":[{"apiVersion":"v1","kind":"Namespace","metadata":{"name":"oc-env-1"}}]}', $call['stdin']);
        $this->assertSame([['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'oc-env-1']]], $fake->appliedManifests());
    }

    public function test_exec_passes_stdin_and_the_container(): void
    {
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);

        Kubectl::for($local)->exec('opencloud', 'deploy/caddy', ['sh', '-c', 'cat'], stdin: '{}', container: 'caddy');

        $this->assertSame(
            ['exec', '-i', 'deploy/caddy', '--namespace=opencloud', '--container=caddy', '--', 'sh', '-c', 'cat'],
            $fake->calls[0]['args'],
        );
        $this->assertSame('{}', $fake->calls[0]['stdin']);
    }

    public function test_get_returns_null_when_the_object_does_not_exist_and_json_when_it_does(): void
    {
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $fake->respond('get deployment web', new KubectlResult(0, '{"kind":"Deployment","status":{"readyReplicas":2}}'));

        $this->assertNull(Kubectl::for($local)->get('deployment', 'missing', 'oc-env-1'));
        $this->assertSame(['kind' => 'Deployment', 'status' => ['readyReplicas' => 2]], Kubectl::for($local)->get('deployment', 'web', 'oc-env-1'));
    }

    public function test_a_failed_apply_throws_with_the_kubectl_error(): void
    {
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $fake->respond('apply', new KubectlResult(1, '', 'error: the server could not find the requested resource'));

        $this->expectException(KubectlException::class);
        $this->expectExceptionMessage('error: the server could not find the requested resource');

        Kubectl::for($local)->apply([['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'x']]]);
    }

    public function test_the_selector_joins_labels(): void
    {
        $this->assertSame('opencloud.dev/environment=7,opencloud.dev/process=web', Kubectl::selector([
            'opencloud.dev/environment' => '7',
            'opencloud.dev/process' => 'web',
        ]));
    }
}

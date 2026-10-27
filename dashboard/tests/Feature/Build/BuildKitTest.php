<?php

namespace Tests\Feature\Build;

use App\Domains\Builds\BuildKit;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BuildKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_address_is_the_running_buildkit_pod_through_kubectl_exec(): void
    {
        config(['opencloud.buildkit_addr' => null]);
        Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $fake = FakeKubectl::install()->respond('get pods', new KubectlResult(0, json_encode(['items' => [
            ['metadata' => ['name' => 'buildkitd-old', 'deletionTimestamp' => '2026-10-23T10:00:00Z'], 'status' => ['phase' => 'Running']],
            ['metadata' => ['name' => 'buildkitd-new'], 'status' => ['phase' => 'Pending']],
            ['metadata' => ['name' => 'buildkitd-7d9f'], 'status' => ['phase' => 'Running']],
        ]])));

        $this->assertSame('kube-pod://buildkitd-7d9f?namespace=opencloud', BuildKit::address());
        $this->assertSame(['get', 'pods', '--selector=app.kubernetes.io/name=buildkitd', '--namespace=opencloud', '--ignore-not-found', '-o', 'json'], $fake->calls[0]['args']);
    }

    public function test_a_configured_address_wins_and_no_pod_is_an_error(): void
    {
        config(['opencloud.buildkit_addr' => 'tcp://127.0.0.1:1234']);
        $this->assertSame('tcp://127.0.0.1:1234', BuildKit::address());

        config(['opencloud.buildkit_addr' => '']);
        Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        FakeKubectl::install()->respond('get pods', new KubectlResult(0, '{"items":[]}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BuildKit is not running. Check it with: sudo opencloud status');
        BuildKit::address();
    }
}

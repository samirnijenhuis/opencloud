<?php

namespace Tests\Feature\Console;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Jobs\InstallClusterParts;
use App\Models\AuditEvent;
use App\Models\Cluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_the_local_cluster_once(): void
    {
        $this->artisan('opencloud:bootstrap')->assertSuccessful();
        $this->artisan('opencloud:bootstrap')->assertSuccessful();

        $cluster = Cluster::sole();
        $this->assertSame('local', $cluster->name);
        $this->assertSame(ClusterKind::Local, $cluster->kind);
        $this->assertSame([ClusterStatus::Ready, 'dev'], [$cluster->status, $cluster->parts_version]);
        $this->assertNull($cluster->kubeconfig);
    }

    public function test_bootstrap_keeps_other_clusters(): void
    {
        Cluster::factory()->create(['name' => 'do-ams3', 'kind' => ClusterKind::Connected]);

        $this->artisan('opencloud:bootstrap')->assertSuccessful();

        $this->assertSame(
            ['do-ams3' => 'connected', 'local' => 'local'],
            Cluster::orderBy('name')->get()->mapWithKeys(fn (Cluster $c) => [$c->name => $c->kind->value])->all(),
        );
    }

    public function test_after_an_upgrade_bootstrap_updates_the_parts_of_clusters_from_another_release(): void
    {
        config(['opencloud.version' => 'v1.2.0']);
        Queue::fake();
        $old = Cluster::factory()->create(['name' => 'old', 'status' => ClusterStatus::Ready, 'parts_version' => 'v1.1.0']);
        Cluster::factory()->create(['name' => 'failed-old', 'status' => ClusterStatus::Failed, 'parts_version' => 'v1.0.0', 'error' => 'timeout']);
        Cluster::factory()->create(['name' => 'current', 'status' => ClusterStatus::Ready, 'parts_version' => 'v1.2.0']);
        Cluster::factory()->create(['name' => 'installing', 'status' => ClusterStatus::Installing, 'parts_version' => 'v1.1.0']);
        Cluster::factory()->create(['name' => 'never-installed', 'status' => ClusterStatus::Failed, 'parts_version' => null]);

        $this->artisan('opencloud:bootstrap')
            ->expectsOutput('Updating the parts on cluster old from v1.1.0 to v1.2.0.')
            ->assertSuccessful();

        $this->assertSame(
            ['failed-old', 'old'],
            Queue::pushed(InstallClusterParts::class)->map(fn (InstallClusterParts $job) => $job->cluster->name)->sort()->values()->all(),
        );
        $this->assertSame(ClusterStatus::Installing, $old->fresh()->status);
        $this->assertSame(
            [['cluster.parts_reinstalled', null], ['cluster.parts_reinstalled', null]],
            AuditEvent::query()->get()->map(fn (AuditEvent $event) => [$event->action, $event->user_id])->all(),
        );
    }
}

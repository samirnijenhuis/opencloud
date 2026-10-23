<?php

namespace Tests\Feature\Console;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Models\Cluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

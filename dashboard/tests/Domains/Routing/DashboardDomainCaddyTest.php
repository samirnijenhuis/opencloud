<?php

namespace Tests\Domains\Routing;

use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\RemoveDashboardDomain;
use App\Domains\Routing\CaddyConfig;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\DashboardDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardDomainCaddyTest extends TestCase
{
    use RefreshDatabase;

    private function configJson(Cluster $cluster): string
    {
        return json_encode((new CaddyConfig('internal', null))->forCluster($cluster), JSON_UNESCAPED_SLASHES);
    }

    private const WITHOUT = '{"apps":{"http":{"metrics":{"per_host":true},"servers":{'.
        '"dashboard":{"listen":[":8000"],"routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]},'.
        '"apps":{"listen":[":443"],"routes":[],'.
        '"errors":{"routes":[{"match":[{"expression":"{http.error.status_code} == 502"}],"handle":[{"handler":"rewrite","uri":"/_opencloud/wake/{http.request.host}"},{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]}'.
        '}}},'.
        '"tls":{"automation":{"policies":[{"issuers":[{"module":"internal"}]}]}}}}';

    private const WITH = '{"apps":{"http":{"metrics":{"per_host":true},"servers":{'.
        '"dashboard":{"listen":[":8000"],"routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]},'.
        '"apps":{"listen":[":443"],"routes":['.
        '{"match":[{"host":["admin.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}'.
        '],'.
        '"errors":{"routes":[{"match":[{"expression":"{http.error.status_code} == 502"}],"handle":[{"handler":"rewrite","uri":"/_opencloud/wake/{http.request.host}"},{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]}'.
        '}}},"tls":{"automation":{"policies":[{"issuers":[{"module":"internal"}]}]}}}}';

    public function test_a_verified_domain_gets_a_route_to_the_dashboard_on_the_local_cluster(): void
    {
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL]);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::OK]);

        $this->assertSame(self::WITH, $this->configJson($local));
    }

    public function test_a_proxied_domain_gets_the_route_too(): void
    {
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL]);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::PROXIED]);

        $this->assertSame(self::WITH, $this->configJson($local));
    }

    public function test_without_a_domain_or_before_the_check_passes_the_config_has_no_dashboard_host(): void
    {
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL]);
        $this->assertSame(self::WITHOUT, $this->configJson($local));

        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::WRONG]);
        $this->assertSame(self::WITHOUT, $this->configJson($local));
    }

    public function test_another_cluster_never_gets_the_dashboard_route(): void
    {
        $connected = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);
        DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::OK]);

        $this->assertStringNotContainsString('admin.example.com', $this->configJson($connected));
    }

    public function test_another_cluster_reaches_the_wake_route_on_port_8000_of_the_server(): void
    {
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.10']);
        $connected = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);

        $errors = (new CaddyConfig('internal', null))->forCluster($connected)['apps']['http']['servers']['apps']['errors'];

        $this->assertSame([['dial' => '203.0.113.10:8000']], $errors['routes'][0]['handle'][1]['upstreams']);
    }

    public function test_another_cluster_has_no_wake_route_while_the_server_has_no_address(): void
    {
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => null]);
        $connected = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);

        $this->assertArrayNotHasKey('errors', (new CaddyConfig('internal', null))->forCluster($connected)['apps']['http']['servers']['apps']);
    }

    public function test_a_removed_domain_leaves_the_route_and_syncs_caddy(): void
    {
        Queue::fake();
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL]);
        $domain = DashboardDomain::create(['hostname' => 'admin.example.com', 'dns_status' => DnsStatus::OK]);

        app(RemoveDashboardDomain::class)->execute($domain, User::factory()->owner()->create());

        $this->assertSame(self::WITHOUT, $this->configJson($local));
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $sync) => $sync->cluster->is($local));
        $this->assertTrue(Cache::has('illuminate:queue:restart'));
    }
}

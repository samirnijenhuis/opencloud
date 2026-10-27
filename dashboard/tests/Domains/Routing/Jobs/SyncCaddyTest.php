<?php

namespace Tests\Domains\Routing\Jobs;

use App\Domains\Applications\Models\Application;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCaddyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_routes_for_checked_domains_of_live_environments_on_the_cluster(): void
    {
        config(['opencloud.tls_issuer' => 'internal']);
        $fake = FakeKubectl::install();
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $other = Cluster::factory()->create(['name' => 'do-ams3']);

        $live = $this->environment($local, DeploymentStatus::LIVE);
        Domain::factory()->for($live)->create(['hostname' => 'shop.example.com', 'dns_status' => DnsStatus::OK]);
        Domain::factory()->for($live)->create(['hostname' => 'cdn.example.com', 'dns_status' => DnsStatus::PROXIED]);
        Domain::factory()->for($live)->create(['hostname' => 'pending.example.com']);
        Domain::factory()->for($live)->create(['hostname' => 'wrong.example.com', 'dns_status' => DnsStatus::WRONG]);
        $notLive = $this->environment($local, DeploymentStatus::FAILED);
        Domain::factory()->for($notLive)->create(['hostname' => 'failed.example.com', 'dns_status' => DnsStatus::OK]);
        $elsewhere = $this->environment($other, DeploymentStatus::LIVE);
        Domain::factory()->for($elsewhere)->create(['hostname' => 'other.example.com', 'dns_status' => DnsStatus::OK]);

        SyncCaddy::dispatch($local);

        $upstream = "web.oc-env-{$live->id}.svc.cluster.local:8080";
        $this->assertSame([[
            'cluster' => 'local',
            'args' => [
                'exec', '-i', 'deploy/caddy', '--namespace=opencloud', '--',
                'sh', '-c', 'cat > /tmp/caddy.json && wget -q -O- --header "Content-Type: application/json" --post-file /tmp/caddy.json http://127.0.0.1:2019/load',
            ],
            'stdin' => '{"apps":{"http":{"servers":{'.
                '"dashboard":{"listen":[":8000"],"routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]},'.
                '"apps":{"listen":[":443"],"routes":['.
                '{"match":[{"host":["cdn.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"'.$upstream.'"}],"load_balancing":{"try_duration":"5s"}}]},'.
                '{"match":[{"host":["shop.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"'.$upstream.'"}],"load_balancing":{"try_duration":"5s"}}]}'.
                ']}}},"tls":{"automation":{"policies":[{"issuers":[{"module":"internal"}]}]}}}}',
        ]], $fake->calls);
    }

    public function test_a_cloudflare_domain_gets_a_dns_challenge_policy_with_the_stored_token(): void
    {
        config(['opencloud.tls_issuer' => 'acme', 'opencloud.acme_email' => null]);
        $fake = FakeKubectl::install();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $live = $this->environment($cluster, DeploymentStatus::LIVE);
        Domain::factory()->for($live)->create(['hostname' => 'app.example.com', 'dns_status' => DnsStatus::PROXIED, 'dns_mode' => DnsMode::CLOUDFLARE]);
        Domain::factory()->for($live)->create(['hostname' => 'manual.example.org', 'dns_status' => DnsStatus::OK]);
        ProviderCredential::factory()->create(['provider' => 'cloudflare', 'token' => 'cf-token']);

        SyncCaddy::dispatch($cluster);

        $this->assertStringEndsWith(
            '"tls":{"automation":{"policies":['.
            '{"subjects":["app.example.com"],"issuers":[{"module":"acme","challenges":{"dns":{"provider":{"name":"cloudflare","api_token":"cf-token"}}}}]},'.
            '{"issuers":[{"module":"acme"}]}]}}}}',
            $fake->calls[0]['stdin'],
        );
    }

    public function test_the_scheduler_syncs_every_ready_cluster_every_five_minutes(): void
    {
        Queue::fake();
        $local = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'status' => ClusterStatus::READY]);
        $other = Cluster::factory()->create(['name' => 'do-ams3', 'status' => ClusterStatus::READY]);
        Cluster::factory()->create(['name' => 'still-installing', 'status' => ClusterStatus::INSTALLING]);
        Cluster::factory()->create(['name' => 'broken', 'status' => ClusterStatus::FAILED]);

        $event = collect(app(Schedule::class)->events())->first(fn (Event $event) => $event->description === 'sync-caddy');
        $this->assertSame('*/5 * * * *', $event->expression);

        $event->run($this->app);

        $this->assertSame(
            [$local->id, $other->id],
            Queue::pushed(SyncCaddy::class)->map(fn (SyncCaddy $job) => $job->cluster->id)->sort()->values()->all(),
        );
    }

    private function environment(Cluster $cluster, DeploymentStatus $status): Environment
    {
        $environment = Environment::factory()->for(Application::factory()->for($cluster))->create();
        Deployment::factory()->for($environment)->create(['status' => $status]);

        return $environment;
    }
}

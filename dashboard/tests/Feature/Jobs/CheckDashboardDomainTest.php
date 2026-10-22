<?php

namespace Tests\Feature\Jobs;

use App\Domains\DnsResolver;
use App\Domains\DnsStatus;
use App\Enums\ClusterKind;
use App\Jobs\CheckDashboardDomain;
use App\Jobs\SyncCaddy;
use App\Models\Cluster;
use App\Models\DashboardDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckDashboardDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: list<string>, 1: DnsStatus, 2: bool}> */
    public static function answers(): array
    {
        return [
            'the ingress ip' => [['203.0.113.10'], DnsStatus::Ok, true],
            'cloudflare only' => [['104.16.132.229'], DnsStatus::Proxied, true],
            'another host' => [['198.51.100.1'], DnsStatus::Wrong, false],
            'no records' => [[], DnsStatus::Missing, false],
        ];
    }

    /** @param  list<string>  $addresses */
    #[DataProvider('answers')]
    public function test_the_status_follows_the_a_records_and_a_pass_syncs_the_local_cluster(array $addresses, DnsStatus $expected, bool $passes): void
    {
        Queue::fake();
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'ingress_ip' => '203.0.113.10']);
        $domain = DashboardDomain::create(['hostname' => 'admin.example.com']);
        $this->app->instance(DnsResolver::class, new class($addresses) extends DnsResolver
        {
            /** @param  list<string>  $addresses */
            public function __construct(private array $addresses) {}

            public function ipv4(string $hostname): array
            {
                return $this->addresses;
            }
        });

        $job = (new CheckDashboardDomain($domain))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(DnsResolver::class));

        $this->assertSame($expected, $domain->fresh()->dns_status);
        $this->assertSame($addresses, $domain->fresh()->dns_found);
        $this->assertNotNull($domain->fresh()->dns_checked_at);

        if ($passes) {
            Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $sync) => $sync->cluster->is($local));
            $job->assertNotReleased();
        } else {
            Queue::assertNotPushed(SyncCaddy::class);
            $job->assertReleased(delay: 240);
        }
    }

    public function test_the_last_try_does_not_release_again(): void
    {
        Queue::fake();
        Cluster::factory()->create(['kind' => ClusterKind::Local, 'ingress_ip' => '203.0.113.10']);
        $domain = DashboardDomain::create(['hostname' => 'admin.example.com']);

        $job = (new CheckDashboardDomain($domain))->withFakeQueueInteractions();
        $job->job->attempts = 48;
        $job->handle(new DnsResolver);

        $job->assertNotReleased();
    }
}

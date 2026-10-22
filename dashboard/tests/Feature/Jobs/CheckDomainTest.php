<?php

namespace Tests\Feature\Jobs;

use App\Domains\DnsResolver;
use App\Domains\DnsStatus;
use App\Jobs\CheckDomain;
use App\Jobs\SyncCaddy;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Domain;
use App\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: list<string>, 1: DnsStatus}> */
    public static function answers(): array
    {
        return [
            'the ingress ip' => [['203.0.113.10'], DnsStatus::Ok],
            'the ingress ip among others' => [['198.51.100.1', '203.0.113.10'], DnsStatus::Ok],
            'cloudflare only' => [['104.16.132.229', '172.67.10.1'], DnsStatus::Proxied],
            'cloudflare and another host' => [['104.16.132.229', '198.51.100.1'], DnsStatus::Wrong],
            'another host' => [['198.51.100.1'], DnsStatus::Wrong],
            'no records' => [[], DnsStatus::Missing],
        ];
    }

    /** @param  list<string>  $addresses */
    #[DataProvider('answers')]
    public function test_the_status_follows_the_a_records(array $addresses, DnsStatus $expected): void
    {
        $this->assertSame($expected, DnsStatus::forAddresses($addresses, '203.0.113.10'));
    }

    public function test_a_cluster_without_an_ingress_ip_never_passes_on_its_own_address(): void
    {
        $this->assertSame(DnsStatus::Wrong, DnsStatus::forAddresses(['203.0.113.10'], null));
    }

    public function test_a_domain_that_points_at_the_cluster_is_ok_and_syncs_caddy(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $domain = $this->domain('shop.example.com');
        $this->resolve(['shop.example.com' => ['203.0.113.10']]);

        $job = (new CheckDomain($domain))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));

        $domain->refresh();
        $this->assertSame(DnsStatus::Ok, $domain->dns_status);
        $this->assertSame(['203.0.113.10'], $domain->dns_found);
        $this->assertSame('2026-10-23 12:00:00', $domain->dns_checked_at->toDateTimeString());
        $this->assertSame([$domain->environment->application->cluster_id], Queue::pushed(SyncCaddy::class)->map(fn (SyncCaddy $sync) => $sync->cluster->id)->all());
        $job->assertNotReleased();
    }

    public function test_a_proxied_domain_syncs_caddy(): void
    {
        Queue::fake();
        $domain = $this->domain('cdn.example.com');
        $this->resolve(['cdn.example.com' => ['104.21.5.5']]);

        $job = (new CheckDomain($domain))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));

        $this->assertSame(DnsStatus::Proxied, $domain->fresh()->dns_status);
        Queue::assertPushed(SyncCaddy::class, 1);
        $job->assertNotReleased();
    }

    public function test_a_wrong_domain_stores_what_it_found_and_tries_again_later(): void
    {
        Queue::fake();
        $domain = $this->domain('shop.example.com');
        $this->resolve(['shop.example.com' => ['198.51.100.1', '198.51.100.2']]);

        $job = (new CheckDomain($domain))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(DnsResolver::class));

        $this->assertSame(DnsStatus::Wrong, $domain->fresh()->dns_status);
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $domain->fresh()->dns_found);
        Queue::assertNotPushed(SyncCaddy::class);
        $job->assertReleased(delay: 240);
    }

    public function test_the_last_try_does_not_release_again(): void
    {
        Queue::fake();
        $domain = $this->domain('gone.example.com');
        $this->resolve([]);

        $job = (new CheckDomain($domain))->withFakeQueueInteractions();
        $job->job->attempts = 48;
        $job->handle(app(DnsResolver::class));

        $this->assertSame(DnsStatus::Missing, $domain->fresh()->dns_status);
        $job->assertNotReleased();
    }

    public function test_the_delay_grows_to_one_hour(): void
    {
        $this->assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], array_map(CheckDomain::delay(...), [1, 2, 3, 4, 5, 6, 7, 30]));
    }

    public function test_bootstrap_stores_the_public_ip_as_the_local_ingress_ip(): void
    {
        config(['opencloud.public_ip' => '203.0.113.10']);
        $this->artisan('opencloud:bootstrap')->assertSuccessful();
        $this->assertSame('203.0.113.10', Cluster::sole()->ingress_ip);

        config(['opencloud.public_ip' => null]);
        $this->artisan('opencloud:bootstrap')->assertSuccessful();
        $this->assertSame('203.0.113.10', Cluster::sole()->ingress_ip);
    }

    private function domain(string $hostname): Domain
    {
        $cluster = Cluster::factory()->create(['ingress_ip' => '203.0.113.10']);
        $environment = Environment::factory()->for(Application::factory()->for($cluster))->create();

        return Domain::factory()->for($environment)->create(['hostname' => $hostname]);
    }

    /** @param  array<string, list<string>>  $answers */
    private function resolve(array $answers): void
    {
        $this->app->instance(DnsResolver::class, new class($answers) extends DnsResolver
        {
            /** @param  array<string, list<string>>  $answers */
            public function __construct(private array $answers) {}

            public function ipv4(string $hostname): array
            {
                return $this->answers[$hostname] ?? [];
            }
        });
    }
}

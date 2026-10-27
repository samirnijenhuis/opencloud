<?php

namespace Tests\Feature\Jobs;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\CertificateProbe;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckCertificates;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckCertificatesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, string}> */
    private array $probed = [];

    public function test_each_domain_that_points_at_its_cluster_gets_the_state_of_its_certificate(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $cluster = Cluster::factory()->create(['ingress_ip' => '203.0.113.10']);
        $environment = Environment::factory()->for(Application::factory()->for($cluster))->create();
        $served = Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com', 'dns_status' => DnsStatus::Ok]);
        $proxied = Domain::factory()->for($environment)->create(['hostname' => 'cdn.example.com', 'dns_status' => DnsStatus::Proxied]);
        $expired = Domain::factory()->for($environment)->create(['hostname' => 'old.example.com', 'dns_status' => DnsStatus::Ok, 'certificate_status' => 'issued']);
        $waiting = Domain::factory()->for($environment)->create(['hostname' => 'new.example.com', 'dns_status' => DnsStatus::Ok]);
        $elsewhere = Domain::factory()->for($environment)->create(['hostname' => 'away.example.com', 'dns_status' => DnsStatus::Wrong]);
        $this->certificates([
            'shop.example.com' => ['DNS:shop.example.com', now()->addMonths(3)->getTimestamp()],
            'cdn.example.com' => ['DNS:*.example.com', now()->addMonths(3)->getTimestamp()],
            'old.example.com' => ['DNS:old.example.com', now()->subDay()->getTimestamp()],
        ]);

        app()->call([new CheckCertificates, 'handle']);

        $this->assertSame(
            [['203.0.113.10', 'shop.example.com'], ['203.0.113.10', 'cdn.example.com'], ['203.0.113.10', 'old.example.com'], ['203.0.113.10', 'new.example.com']],
            $this->probed,
        );
        $this->assertSame(
            ['issued', 'issued', 'pending', 'pending', 'pending'],
            [$served->fresh()->certificate_status, $proxied->fresh()->certificate_status, $expired->fresh()->certificate_status, $waiting->fresh()->certificate_status, $elsewhere->fresh()->certificate_status],
        );
    }

    /** @param  array<string, array{string, int}>  $served  the names and the end of validity of the certificate for each hostname */
    private function certificates(array $served): void
    {
        $this->app->instance(CertificateProbe::class, new class($served, $this->probed) extends CertificateProbe
        {
            /**
             * @param  array<string, array{string, int}>  $served
             * @param  list<array{string, string}>  $probed
             */
            public function __construct(private array $served, private array &$probed) {}

            public function certificate(string $address, string $hostname): ?array
            {
                $this->probed[] = [$address, $hostname];

                return isset($this->served[$hostname])
                    ? ['validFrom_time_t' => now()->subMonth()->getTimestamp(), 'validTo_time_t' => $this->served[$hostname][1], 'extensions' => ['subjectAltName' => $this->served[$hostname][0]]]
                    : null;
            }
        });
    }
}

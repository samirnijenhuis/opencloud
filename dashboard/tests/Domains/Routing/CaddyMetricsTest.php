<?php

namespace Tests\Domains\Routing;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Routing\CaddyMetrics;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaddyMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** Lines from Caddy 2.11.7 with per_host metrics. A client can send the port in the Host header. */
    private const METRICS = <<<'TEXT'
        # HELP caddy_http_requests_total Counter of HTTP(S) requests made.
        # TYPE caddy_http_requests_total counter
        caddy_http_requests_total{handler="reverse_proxy",host="asleep.test",server="apps"} 3
        caddy_http_requests_total{handler="reverse_proxy",host="asleep.test:443",server="apps"} 1
        caddy_http_requests_total{handler="reverse_proxy",host="awake.test",server="apps"} 1.234567e+06
        caddy_http_requests_total{handler="static_response",host="asleep.test",server="dash"} 3
        caddy_http_requests_total{handler="reverse_proxy",host="203.0.113.10:8000",server="dashboard"} 40
        caddy_http_request_duration_seconds_count{code="502",handler="reverse_proxy",host="asleep.test",method="GET",server="apps"} 2
        TEXT;

    public function test_it_counts_the_requests_of_each_app_hostname(): void
    {
        $kubectl = FakeKubectl::install()->respond('exec deploy/caddy', new KubectlResult(0, self::METRICS));
        $cluster = Cluster::factory()->create(['name' => 'local']);

        $this->assertSame(['asleep.test' => 4, 'awake.test' => 1234567], (new CaddyMetrics)->requestCounts($cluster));
        $this->assertSame(
            ['exec', 'deploy/caddy', '--namespace=opencloud', '--', 'wget', '-q', '-O-', 'http://127.0.0.1:2019/metrics'],
            $kubectl->calls[0]['args'],
        );
    }

    public function test_it_gives_no_counts_when_caddy_does_not_answer(): void
    {
        FakeKubectl::install()->respond('exec deploy/caddy', new KubectlResult(1, '', 'error: unable to upgrade connection: container not found ("caddy")'));

        $this->assertNull((new CaddyMetrics)->requestCounts(Cluster::factory()->create()));
    }
}

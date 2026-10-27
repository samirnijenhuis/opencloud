<?php

namespace Tests\Unit\Caddy;

use App\Domains\Routing\CaddyConfig;
use PHPUnit\Framework\TestCase;

class CaddyConfigTest extends TestCase
{
    public function test_the_local_cluster_serves_the_dashboard_and_sorted_app_routes_with_the_internal_issuer(): void
    {
        $config = (new CaddyConfig('internal', null))->build(true, [
            'x.example.com' => 'web.oc-env-7.svc.cluster.local:8080',
            'a.example.com' => 'web.oc-env-3.svc.cluster.local:8080',
        ]);

        $this->assertSame(
            '{"apps":{"http":{"servers":{'.
            '"dashboard":{"listen":[":8000"],"routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]},'.
            '"apps":{"listen":[":443"],"routes":['.
            '{"match":[{"host":["a.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"web.oc-env-3.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]},'.
            '{"match":[{"host":["x.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"web.oc-env-7.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}'.
            ']}}},"tls":{"automation":{"policies":[{"issuers":[{"module":"internal"}]}]}}}}',
            json_encode($config, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_another_cluster_has_no_dashboard_and_uses_lets_encrypt_with_the_email(): void
    {
        $this->assertSame([
            'apps' => [
                'http' => ['servers' => ['apps' => ['listen' => [':443'], 'routes' => []]]],
                'tls' => ['automation' => ['policies' => [['issuers' => [['module' => 'acme', 'email' => 'ops@example.com']]]]]],
            ],
        ], (new CaddyConfig('acme', 'ops@example.com'))->build(false, []));
    }

    public function test_acme_without_an_email_sends_no_email(): void
    {
        $this->assertSame(
            [['module' => 'acme']],
            (new CaddyConfig('acme', null))->build(false, [])['apps']['tls']['automation']['policies'][0]['issuers'],
        );
    }

    public function test_cloudflare_hostnames_get_a_dns_challenge_policy_before_the_catch_all(): void
    {
        $config = (new CaddyConfig('acme', 'ops@example.com'))->build(
            false,
            ['b.example.com' => 'web.oc-env-2.svc.cluster.local:8080', 'a.example.com' => 'web.oc-env-1.svc.cluster.local:8080', 'manual.example.org' => 'web.oc-env-3.svc.cluster.local:8080'],
            'cf-token',
            ['b.example.com', 'a.example.com'],
        );

        $this->assertSame(
            '{"policies":['.
            '{"subjects":["a.example.com","b.example.com"],"issuers":[{"module":"acme","email":"ops@example.com","challenges":{"dns":{"provider":{"name":"cloudflare","api_token":"cf-token"}}}}]},'.
            '{"issuers":[{"module":"acme","email":"ops@example.com"}]}'.
            ']}',
            json_encode($config['apps']['tls']['automation'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_a_cloudflare_hostname_without_a_route_gets_no_policy(): void
    {
        $config = (new CaddyConfig('acme', null))->build(false, ['a.example.com' => 'x:1'], 'cf-token', ['gone.example.com']);

        $this->assertSame([['issuers' => [['module' => 'acme']]]], $config['apps']['tls']['automation']['policies']);
    }

    public function test_cloudflare_hostnames_without_a_token_use_the_default_policy(): void
    {
        $config = (new CaddyConfig('acme', null))->build(false, ['a.example.com' => 'x:1'], null, ['a.example.com']);

        $this->assertSame([['issuers' => [['module' => 'acme']]]], $config['apps']['tls']['automation']['policies']);
    }

    public function test_the_internal_issuer_never_uses_the_dns_challenge(): void
    {
        $config = (new CaddyConfig('internal', null))->build(false, ['a.example.com' => 'x:1'], 'cf-token', ['a.example.com']);

        $this->assertSame([['issuers' => [['module' => 'internal']]]], $config['apps']['tls']['automation']['policies']);
    }
}

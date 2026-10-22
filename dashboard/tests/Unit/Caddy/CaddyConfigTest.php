<?php

namespace Tests\Unit\Caddy;

use App\Caddy\CaddyConfig;
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
            '"dashboard":{"listen":[":8000"],"routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"dashboard.opencloud.svc.cluster.local:8080"}]}]}]},'.
            '"apps":{"listen":[":80",":443"],"routes":['.
            '{"match":[{"host":["a.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"web.oc-env-3.svc.cluster.local:8080"}]}]},'.
            '{"match":[{"host":["x.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"web.oc-env-7.svc.cluster.local:8080"}]}]}'.
            ']}}},"tls":{"automation":{"policies":[{"issuers":[{"module":"internal"}]}]}}}}',
            json_encode($config, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_another_cluster_has_no_dashboard_and_uses_lets_encrypt_with_the_email(): void
    {
        $this->assertSame([
            'apps' => [
                'http' => ['servers' => ['apps' => ['listen' => [':80', ':443'], 'routes' => []]]],
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
}

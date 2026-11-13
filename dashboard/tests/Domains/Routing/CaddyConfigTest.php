<?php

namespace Tests\Domains\Routing;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Domains\Environments\ToolLogin;
use App\Domains\Routing\CaddyConfig;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaddyConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_local_cluster_serves_the_dashboard_and_sorted_app_routes_with_the_internal_issuer(): void
    {
        $config = (new CaddyConfig('internal', null))->build(true, [
            'x.example.com' => 'web.oc-env-7.svc.cluster.local:8080',
            'a.example.com' => 'web.oc-env-3.svc.cluster.local:8080',
        ]);

        $this->assertSame(
            '{"apps":{"http":{"metrics":{"per_host":true},"servers":{'.
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
                'http' => ['metrics' => ['per_host' => true], 'servers' => ['apps' => ['listen' => [':443'], 'routes' => []]]],
                'tls' => ['automation' => ['policies' => [['issuers' => [['module' => 'acme', 'email' => 'ops@example.com']]]]]],
            ],
        ], (new CaddyConfig('acme', 'ops@example.com'))->build(false, []));
    }

    public function test_a_request_whose_app_does_not_answer_goes_to_the_wake_path_of_the_dashboard(): void
    {
        $config = (new CaddyConfig('internal', null))->build(false, ['a.example.com' => 'web.oc-env-3.svc.cluster.local:8080'], wakeUpstream: '203.0.113.10:8000');

        $this->assertSame(
            '{"routes":[{"match":[{"expression":"{http.error.status_code} == 502"}],"handle":['.
            '{"handler":"rewrite","uri":"/_opencloud/wake/{http.request.host}"},'.
            '{"handler":"reverse_proxy","upstreams":[{"dial":"203.0.113.10:8000"}],"load_balancing":{"try_duration":"5s"}}'.
            ']}]}',
            json_encode($config['apps']['http']['servers']['apps']['errors'], JSON_UNESCAPED_SLASHES),
        );
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

    public function test_a_route_with_an_account_checks_the_basic_auth_login_before_the_proxy(): void
    {
        $config = (new CaddyConfig('internal', null))->build(
            false,
            ['mail-12.example.com' => 'mailpit.oc-env-7.svc.cluster.local:8025', 'pr-12.example.com' => 'web.oc-env-7.svc.cluster.local:8080'],
            accounts: ['mail-12.example.com' => ['username' => 'opencloud', 'password' => '$2y$10$hash']],
        );

        $this->assertSame(
            '[{"match":[{"host":["mail-12.example.com"]}],"handle":['.
            '{"handler":"authentication","providers":{"http_basic":{"accounts":[{"username":"opencloud","password":"$2y$10$hash"}],"hash_cache":{}}}},'.
            '{"handler":"reverse_proxy","upstreams":[{"dial":"mailpit.oc-env-7.svc.cluster.local:8025"}],"load_balancing":{"try_duration":"5s"}}]},'.
            '{"match":[{"host":["pr-12.example.com"]}],"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"web.oc-env-7.svc.cluster.local:8080"}],"load_balancing":{"try_duration":"5s"}}]}]',
            json_encode($config['apps']['http']['servers']['apps']['routes'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_a_preview_with_mailpit_and_phpmyadmin_gets_one_route_for_each_tool_on_its_stored_hostname_behind_its_login(): void
    {
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);
        $preview = $this->preview($cluster, defaultMysql: true);
        // A second domain of the same preview gives no second set of tool routes.
        $this->routableDomain($preview, 'shop-12.example.org');

        $config = (new CaddyConfig('internal', null))->forCluster($cluster);
        $routes = collect($config['apps']['http']['servers']['apps']['routes'])->keyBy(fn (array $route) => $route['match'][0]['host'][0]);
        $namespace = "oc-env-{$preview->id}";

        $this->assertSame(['database-12.staging.hlpbnk.io', 'inbox-12.staging.hlpbnk.io', 'pr-12.staging.hlpbnk.io', 'shop-12.example.org'], $routes->keys()->all());
        $this->assertSame(
            [["phpmyadmin.{$namespace}.svc.cluster.local:80"], ["mailpit.{$namespace}.svc.cluster.local:8025"]],
            [array_column($routes['database-12.staging.hlpbnk.io']['handle'][1]['upstreams'], 'dial'), array_column($routes['inbox-12.staging.hlpbnk.io']['handle'][1]['upstreams'], 'dial')],
        );

        $login = ToolLogin::for($preview);
        foreach (['database-12.staging.hlpbnk.io', 'inbox-12.staging.hlpbnk.io'] as $hostname) {
            $auth = $routes[$hostname]['handle'][0];
            $account = $auth['providers']['http_basic']['accounts'][0];
            $this->assertSame(['authentication', 'opencloud', '{}'], [$auth['handler'], $account['username'], json_encode($auth['providers']['http_basic']['hash_cache'])]);
            $this->assertTrue(password_verify($login->password, $account['password']));
        }
        $this->assertSame(['reverse_proxy'], array_column($routes['pr-12.staging.hlpbnk.io']['handle'], 'handler'));
        $this->assertSame(json_encode($config), json_encode((new CaddyConfig('internal', null))->forCluster($cluster)), 'Caddy reloads only a config that changed.');
    }

    public function test_a_preview_without_a_default_mysql_database_gets_no_phpmyadmin_route(): void
    {
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);
        $this->preview($cluster, defaultMysql: false);

        $hosts = array_map(fn (array $route) => $route['match'][0]['host'][0], (new CaddyConfig('internal', null))->forCluster($cluster)['apps']['http']['servers']['apps']['routes']);

        $this->assertSame(['inbox-12.staging.hlpbnk.io', 'pr-12.staging.hlpbnk.io'], $hosts);
    }

    public function test_a_preview_whose_domain_is_not_routable_gets_no_tool_route(): void
    {
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::CONNECTED]);
        $preview = $this->preview($cluster, defaultMysql: true);
        $preview->domains()->update(['dns_status' => DnsStatus::MISSING]);

        $this->assertSame([], (new CaddyConfig('internal', null))->forCluster($cluster)['apps']['http']['servers']['apps']['routes']);
    }

    public function test_the_internal_issuer_never_uses_the_dns_challenge(): void
    {
        $config = (new CaddyConfig('internal', null))->build(false, ['a.example.com' => 'x:1'], 'cf-token', ['a.example.com']);

        $this->assertSame([['issuers' => [['module' => 'internal']]]], $config['apps']['tls']['automation']['policies']);
    }

    /** Preview 12 with Mailpit and phpMyAdmin on their own hostnames, a live deployment, and a routable domain. */
    private function preview(Cluster $cluster, bool $defaultMysql): Environment
    {
        $application = Application::factory()->for($cluster)->create(['preview_domain' => 'pr-{pr_number}.staging.hlpbnk.io']);
        $preview = Environment::factory()->for($application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12]);
        Tool::factory()->for($preview)->create(['kind' => ToolKind::MAILPIT, 'hostname' => 'inbox-12.staging.hlpbnk.io']);
        Tool::factory()->for($preview)->create(['kind' => ToolKind::PHPMYADMIN, 'hostname' => 'database-12.staging.hlpbnk.io']);
        Deployment::factory()->for($preview)->create(['status' => DeploymentStatus::LIVE]);
        $this->routableDomain($preview, 'pr-12.staging.hlpbnk.io');
        DatastoreAttachment::factory()->for(Datastore::factory()->for($cluster)->create(['kind' => DatastoreKind::MYSQL]))->for($preview)->create([
            'name' => $defaultMysql ? 'main' : 'reports',
            'is_default' => $defaultMysql,
            'host' => 'mysql-1.oc-datastores.svc.cluster.local',
            'port' => 3306,
        ]);

        return $preview;
    }

    private function routableDomain(Environment $environment, string $hostname): void
    {
        Domain::factory()->for($environment)->create(['hostname' => $hostname, 'dns_status' => DnsStatus::OK]);
    }
}

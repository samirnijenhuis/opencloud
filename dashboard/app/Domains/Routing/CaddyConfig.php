<?php

namespace App\Domains\Routing;

use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\EnvironmentManifests;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\DashboardDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Database\Eloquent\Builder;

/**
 * The full Caddy JSON config of one cluster. build() is pure. forCluster() reads the routes from the database.
 */
final readonly class CaddyConfig
{
    public const DASHBOARD_UPSTREAM = 'dashboard.opencloud.svc.cluster.local:8080';

    /** The health route, which install.sh and bin/opencloud wait for on port 8000, and the routes in routes/webhooks.php. */
    public const PLAIN_HTTP_PATHS = ['/up', '/webhooks/github', '/hooks/deploy/*'];

    public function __construct(private string $tlsIssuer, private ?string $acmeEmail) {}

    public static function fromConfig(): self
    {
        return new self(config('opencloud.tls_issuer'), config('opencloud.acme_email'));
    }

    /** @return array<string, mixed> */
    public function forCluster(Cluster $cluster): array
    {
        $domains = Domain::query()
            ->with('environment')
            ->whereIn('dns_status', array_filter(DnsStatus::cases(), fn (DnsStatus $status) => $status->routable()))
            ->whereHas('environment', fn (Builder $environment) => $environment
                ->whereHas('application', fn (Builder $application) => $application->where('cluster_id', $cluster->id))
                ->whereHas('deployments', fn (Builder $deployment) => $deployment->where('status', DeploymentStatus::LIVE)))
            ->get();

        $routes = $domains
            ->mapWithKeys(fn (Domain $domain) => [
                $domain->hostname => "web.{$domain->environment->kubeNamespace()}.svc.cluster.local:".EnvironmentManifests::SERVICE_PORT,
            ])
            ->all();

        // The dashboard host is a route like an app host. Caddy then gets its certificate and redirects HTTP to HTTPS.
        $dashboardHostname = null;
        if ($cluster->kind === ClusterKind::LOCAL && ($dashboard = DashboardDomain::verified()) !== null) {
            $routes[$dashboard->hostname] = self::DASHBOARD_UPSTREAM;
            $dashboardHostname = $dashboard->hostname;
        }

        $cloudflareToken = ProviderCredential::query()->where('provider', 'cloudflare')->first()?->token;
        $cloudflareHosts = $domains->where('dns_mode', DnsMode::CLOUDFLARE)->pluck('hostname')->all();

        return $this->build($cluster->kind === ClusterKind::LOCAL, $routes, $cloudflareToken, $cloudflareHosts, $dashboardHostname);
    }

    /**
     * @param  array<string, string>  $routes  hostname => upstream address
     * @param  list<string>  $cloudflareHosts  hostnames that get their certificate with the Cloudflare DNS challenge
     * @param  ?string  $dashboardHostname  the verified dashboard domain. Port 8000 then serves only PLAIN_HTTP_PATHS and redirects the rest to it.
     * @return array<string, mixed>
     */
    public function build(bool $withDashboard, array $routes, ?string $cloudflareToken = null, array $cloudflareHosts = [], ?string $dashboardHostname = null): array
    {
        ksort($routes, SORT_STRING);
        $servers = [];

        if ($withDashboard) {
            $servers['dashboard'] = ['listen' => [':8000'], 'routes' => self::dashboardRoutes($dashboardHostname)];
        }

        $servers['apps'] = [
            'listen' => [':443'],
            'routes' => array_map(
                fn (string $hostname, string $upstream) => ['match' => [['host' => [$hostname]]], 'handle' => [self::proxy($upstream)]],
                array_keys($routes),
                array_values($routes),
            ),
        ];

        return [
            'apps' => [
                'http' => ['servers' => $servers],
                'tls' => ['automation' => ['policies' => $this->policies($cloudflareToken, array_values(array_intersect($cloudflareHosts, array_keys($routes))))]],
            ],
        ];
    }

    /**
     * The policy with subjects comes first. The last policy has no subjects and serves every other hostname.
     *
     * @param  list<string>  $cloudflareHosts
     * @return list<array<string, mixed>>
     */
    private function policies(?string $cloudflareToken, array $cloudflareHosts): array
    {
        $policies = [];

        // The local CA needs no DNS challenge.
        if ($cloudflareToken !== null && $cloudflareHosts !== [] && $this->tlsIssuer !== 'internal') {
            sort($cloudflareHosts, SORT_STRING);
            $dns = ['dns' => ['provider' => ['name' => 'cloudflare', 'api_token' => $cloudflareToken]]];
            $policies[] = ['subjects' => $cloudflareHosts, 'issuers' => [[...$this->issuer(), 'challenges' => $dns]]];
        }

        $policies[] = ['issuers' => [$this->issuer()]];

        return $policies;
    }

    /** @return array<string, string> */
    private function issuer(): array
    {
        if ($this->tlsIssuer === 'internal') {
            return ['module' => 'internal'];
        }

        return ['module' => 'acme', ...($this->acmeEmail ? ['email' => $this->acmeEmail] : [])];
    }

    /**
     * With a verified domain, port 8000 serves only the paths that use no session, and redirects the rest to HTTPS. A GitHub App from before
     * the domain has the webhook URL http://<ip>:8000/webhooks/github, and a CI job calls a deploy hook with plain curl. Neither follows a redirect.
     * curl -f also counts a redirect as a success, so the health check of the installer would pass without the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    private static function dashboardRoutes(?string $hostname): array
    {
        $dashboard = ['handle' => [self::proxy(self::DASHBOARD_UPSTREAM)]];

        if ($hostname === null) {
            return [$dashboard];
        }

        return [
            ['match' => [['path' => self::PLAIN_HTTP_PATHS]], ...$dashboard],
            ['handle' => [['handler' => 'static_response', 'status_code' => 308, 'headers' => ['Location' => ['https://'.$hostname.'{http.request.uri}']]]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function proxy(string $upstream): array
    {
        // A dial that fails during a rollout is tried again for a few seconds instead of returning 502.
        return ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $upstream]], 'load_balancing' => ['try_duration' => '5s']];
    }
}

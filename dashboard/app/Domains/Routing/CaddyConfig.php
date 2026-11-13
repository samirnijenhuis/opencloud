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

    /** The Caddy server of the app hostnames. */
    public const APP_SERVER = 'apps';

    /** The dashboard path that gets each request whose app does not answer. The hostname of the request follows it. */
    public const WAKE_PATH = '/_opencloud/wake/';

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
        if ($cluster->kind === ClusterKind::LOCAL && ($dashboard = DashboardDomain::verified()) !== null) {
            $routes[$dashboard->hostname] = self::DASHBOARD_UPSTREAM;
        }

        $cloudflareToken = ProviderCredential::query()->where('provider', 'cloudflare')->first()?->token;
        $cloudflareHosts = $domains->where('dns_mode', DnsMode::CLOUDFLARE)->pluck('hostname')->all();

        return $this->build($cluster->kind === ClusterKind::LOCAL, $routes, $cloudflareToken, $cloudflareHosts, self::wakeUpstream($cluster));
    }

    /**
     * Caddy on another cluster cannot resolve the dashboard Service, so it reaches the dashboard on port 8000 of the server.
     * Without the address of the server, a request to an app that does not answer gets the plain 502 of Caddy.
     */
    private static function wakeUpstream(Cluster $cluster): ?string
    {
        if ($cluster->kind === ClusterKind::LOCAL) {
            return self::DASHBOARD_UPSTREAM;
        }

        $server = DashboardDomain::localCluster()?->ingress_ip;

        return $server === null ? null : "{$server}:8000";
    }

    /**
     * @param  array<string, string>  $routes  hostname => upstream address
     * @param  list<string>  $cloudflareHosts  hostnames that get their certificate with the Cloudflare DNS challenge
     * @param  string|null  $wakeUpstream  the address of the dashboard for a request whose app does not answer
     * @return array<string, mixed>
     */
    public function build(bool $withDashboard, array $routes, ?string $cloudflareToken = null, array $cloudflareHosts = [], ?string $wakeUpstream = null): array
    {
        ksort($routes, SORT_STRING);
        $servers = [];

        if ($withDashboard) {
            $servers['dashboard'] = ['listen' => [':8000'], 'routes' => [['handle' => [self::proxy(self::DASHBOARD_UPSTREAM)]]]];
        }

        $servers[self::APP_SERVER] = [
            'listen' => [':443'],
            'routes' => array_map(
                fn (string $hostname, string $upstream) => ['match' => [['host' => [$hostname]]], 'handle' => [self::proxy($upstream)]],
                array_keys($routes),
                array_values($routes),
            ),
        ];

        // Caddy answers 502 for an app without a ready pod, for example while it sleeps. The dashboard wakes it and shows a Starting page.
        if ($wakeUpstream !== null) {
            $servers[self::APP_SERVER]['errors'] = ['routes' => [[
                'match' => [['expression' => '{http.error.status_code} == 502']],
                'handle' => [['handler' => 'rewrite', 'uri' => self::WAKE_PATH.'{http.request.host}'], self::proxy($wakeUpstream)],
            ]]];
        }

        return [
            'apps' => [
                // The request count of each hostname tells the dashboard which environments are idle.
                'http' => ['metrics' => ['per_host' => true], 'servers' => $servers],
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

    /** @return array<string, mixed> */
    private static function proxy(string $upstream): array
    {
        // A dial that fails during a rollout is tried again for a few seconds instead of returning 502.
        return ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $upstream]], 'load_balancing' => ['try_duration' => '5s']];
    }
}

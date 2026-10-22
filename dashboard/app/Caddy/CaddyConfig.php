<?php

namespace App\Caddy;

use App\Domains\DnsStatus;
use App\Enums\ClusterKind;
use App\Enums\DeploymentStatus;
use App\Enums\DnsMode;
use App\Kube\Manifests\EnvironmentManifests;
use App\Models\Cluster;
use App\Models\Domain;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Builder;

/**
 * The full Caddy JSON config of one cluster. build() is pure. forCluster() reads the routes from the database.
 */
final readonly class CaddyConfig
{
    public const DASHBOARD_UPSTREAM = 'dashboard.opencloud.svc.cluster.local:8080';

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
                ->whereHas('deployments', fn (Builder $deployment) => $deployment->where('status', DeploymentStatus::Live)))
            ->get();

        $routes = $domains
            ->mapWithKeys(fn (Domain $domain) => [
                $domain->hostname => "web.{$domain->environment->kubeNamespace()}.svc.cluster.local:".EnvironmentManifests::SERVICE_PORT,
            ])
            ->all();

        $cloudflareToken = ProviderCredential::query()->where('provider', 'cloudflare')->first()?->token;
        $cloudflareHosts = $domains->where('dns_mode', DnsMode::Cloudflare)->pluck('hostname')->all();

        return $this->build($cluster->kind === ClusterKind::Local, $routes, $cloudflareToken, $cloudflareHosts);
    }

    /**
     * @param  array<string, string>  $routes  hostname => upstream address
     * @param  list<string>  $cloudflareHosts  hostnames that get their certificate with the Cloudflare DNS challenge
     * @return array<string, mixed>
     */
    public function build(bool $withDashboard, array $routes, ?string $cloudflareToken = null, array $cloudflareHosts = []): array
    {
        ksort($routes, SORT_STRING);
        $servers = [];

        if ($withDashboard) {
            $servers['dashboard'] = ['listen' => [':8000'], 'routes' => [['handle' => [self::proxy(self::DASHBOARD_UPSTREAM)]]]];
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

    /** @return array<string, mixed> */
    private static function proxy(string $upstream): array
    {
        return ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $upstream]]];
    }
}

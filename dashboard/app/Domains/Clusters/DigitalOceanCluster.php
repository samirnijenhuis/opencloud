<?php

namespace App\Domains\Clusters;

use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Models\Cluster;
use App\Support\Tofu\Stack;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The DigitalOcean side of a created cluster: the choices of the form, the stack of tofu/digitalocean/cluster, and
 * the cluster fields from its outputs.
 */
final class DigitalOceanCluster implements ClusterProvider
{
    /** @var array<string, string> slug => label, the regions with Kubernetes */
    private const REGIONS = [
        'ams3' => 'Amsterdam',
        'atl1' => 'Atlanta',
        'blr1' => 'Bangalore',
        'fra1' => 'Frankfurt',
        'lon1' => 'London',
        'nyc1' => 'New York 1',
        'nyc3' => 'New York 3',
        'sfo3' => 'San Francisco',
        'sgp1' => 'Singapore',
        'syd1' => 'Sydney',
        'tor1' => 'Toronto',
    ];

    /** @var array<string, string> slug => label */
    private const NODE_SIZES = [
        's-1vcpu-2gb' => '1 vCPU, 2 GB',
        's-2vcpu-4gb' => '2 vCPU, 4 GB',
        's-4vcpu-8gb' => '4 vCPU, 8 GB',
        's-8vcpu-16gb' => '8 vCPU, 16 GB',
    ];

    private const API = 'https://api.digitalocean.com/v2';

    public function key(): string
    {
        return 'digitalocean';
    }

    public function label(): string
    {
        return 'DigitalOcean';
    }

    public function credential(): string
    {
        return 'digitalocean';
    }

    public function regions(): array
    {
        return self::REGIONS;
    }

    public function nodeSizes(): array
    {
        return self::NODE_SIZES;
    }

    public function maxNodes(): int
    {
        return 10;
    }

    public function pricingUrl(): string
    {
        return 'https://www.digitalocean.com/pricing/kubernetes';
    }

    public static function token(): string
    {
        return ProviderCredential::query()->where('provider', 'digitalocean')->first()?->token
            ?? throw new RuntimeException('There is no DigitalOcean key. Add one in Settings, Provider keys.');
    }

    /**
     * The registry is looked up on each run. When an earlier run created it, the module now reads it instead, and the
     * destroy = false rule of the module only forgets it, so a second run converges.
     */
    public function stack(Cluster $cluster): Stack
    {
        $token = self::token();
        [$registry, $create] = self::registry($token);

        return new Stack('digitalocean/cluster', "cluster-{$cluster->id}", [
            'name' => $cluster->name,
            'region' => $cluster->region,
            'node_size' => $cluster->node_size,
            'min_nodes' => $cluster->min_nodes,
            'max_nodes' => $cluster->max_nodes,
            'registry_name' => $registry,
            'create_registry' => $create,
        ], ['DIGITALOCEAN_TOKEN' => $token]);
    }

    /**
     * @param  array<string, mixed>  $outputs  the outputs of tofu/digitalocean/cluster
     * @return array<string, mixed>
     */
    public function attributes(array $outputs): array
    {
        return [
            'kubeconfig' => $outputs['kubeconfig'],
            'provider_cluster_id' => $outputs['cluster_id'],
            'provider_vpc_id' => $outputs['vpc_id'],
            'registry_url' => $outputs['registry_endpoint'],
            'registry_username' => $outputs['registry_username'],
            'registry_password' => $outputs['registry_password'],
            'registry_insecure' => false,
        ];
    }

    /**
     * DigitalOcean allows one registry per account, and its name is unique across DigitalOcean.
     *
     * @return array{0: string, 1: bool} the registry name, and true when the module must create it
     */
    private static function registry(string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->timeout(15)->get(self::API.'/registry');

        if ($response->status() === 404) {
            return ['opencloud-'.Str::lower(Str::random(10)), true];
        }

        if (! $response->successful()) {
            throw new RuntimeException("DigitalOcean answered {$response->status()} for the registry of the account. ".($response->json('message') ?? ''));
        }

        return [(string) $response->json('registry.name'), false];
    }
}

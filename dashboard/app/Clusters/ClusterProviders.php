<?php

namespace App\Clusters;

use App\Models\ProviderCredential;
use Illuminate\Support\Collection;

final class ClusterProviders
{
    /** @var Collection<string, ClusterProvider> */
    private Collection $providers;

    /** @param  list<ClusterProvider>|null  $providers  null means the providers that opencloud ships */
    public function __construct(?array $providers = null)
    {
        $this->providers = collect($providers ?? [new DigitalOceanCluster])->keyBy(fn (ClusterProvider $provider) => $provider->key());
    }

    /** @return Collection<string, ClusterProvider> */
    public function all(): Collection
    {
        return $this->providers;
    }

    public function find(string $key): ?ClusterProvider
    {
        return $this->providers->get($key);
    }

    public function for(string $key): ClusterProvider
    {
        return $this->find($key) ?? abort(404);
    }

    public function hasCredential(ClusterProvider $provider): bool
    {
        return ProviderCredential::query()->where('provider', $provider->credential())->exists();
    }
}

<?php

namespace App\Domains\Clusters;

use App\Domains\Clusters\Models\Cluster;
use App\Support\Tofu\Stack;

/**
 * A cloud that OpenTofu can create a cluster on. A new provider is one class that implements this interface, one
 * module under tofu/, and one entry in ClusterProviders. The jobs, the actions, the request, and the pages read only
 * this interface.
 */
interface ClusterProvider
{
    /** The value of clusters.provider, and the segment of the create route. */
    public function key(): string;

    public function label(): string;

    /** The key of the ProviderCredential that this provider needs. */
    public function credential(): string;

    /** @return array<string, string> slug => label */
    public function regions(): array;

    /** @return array<string, string> slug => label */
    public function nodeSizes(): array;

    public function maxNodes(): int;

    public function pricingUrl(): string;

    /** The stack of the cluster, with the stored credential in its environment. */
    public function stack(Cluster $cluster): Stack;

    /**
     * @param  array<string, mixed>  $outputs  the outputs of the stack
     * @return array<string, mixed> the columns of the cluster
     */
    public function attributes(array $outputs): array;
}

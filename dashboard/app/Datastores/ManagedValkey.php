<?php

namespace App\Datastores;

use App\Clusters\DigitalOceanCluster;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Tofu\Runner;
use App\Tofu\Stack;

/**
 * A DigitalOcean managed Valkey for one environment. tofu/digitalocean/valkey creates one database cluster with one
 * node. The app logs in as the default user of the cluster, and DigitalOcean accepts only TLS connections.
 */
final class ManagedValkey implements ManagedStack
{
    public function version(): string
    {
        return '8';
    }

    public function names(Environment $environment, ?string $name): array
    {
        return [];
    }

    public function apply(DatastoreAttachment $attachment): array
    {
        $outputs = app(Runner::class)->apply($this->stack($attachment), $attachment->datastore);

        return ['host' => (string) $outputs['host'], 'port' => (int) $outputs['port'], 'password' => (string) $outputs['password'], 'tls' => true];
    }

    public function destroy(DatastoreAttachment $attachment): void
    {
        app(Runner::class)->destroy($this->stack($attachment), $attachment->datastore);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return KeyValueConnection::of($attachment);
    }

    private function stack(DatastoreAttachment $attachment): Stack
    {
        $datastore = $attachment->datastore;
        $cluster = $datastore->cluster;

        return new Stack('digitalocean/valkey', "valkey-{$datastore->id}", [
            'name' => "{$cluster->name}-valkey-{$datastore->id}",
            'engine_version' => $datastore->version,
            'size' => config('opencloud.managed_valkey_size'),
            'region' => $cluster->region,
            'vpc_id' => $cluster->provider_vpc_id,
            'kubernetes_cluster_id' => $cluster->provider_cluster_id,
        ], ['DIGITALOCEAN_TOKEN' => DigitalOceanCluster::token()]);
    }
}

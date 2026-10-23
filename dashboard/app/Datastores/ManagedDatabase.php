<?php

namespace App\Datastores;

use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Models\Cluster;
use App\Models\DatastoreAttachment;
use App\Models\ProviderCredential;
use App\Tofu\Stack;

/**
 * A DigitalOcean managed MySQL or Postgres for one environment. tofu/digitalocean/database creates one database
 * cluster per attachment, so each attachment has its own managed datastore record.
 */
final class ManagedDatabase
{
    public const KINDS = [DatastoreKind::Mysql, DatastoreKind::Postgres];

    /**
     * DigitalOcean MySQL needs TLS with DigitalOcean's own CA, and a stock Laravel app does not load that CA yet.
     * Postgres connects with sslmode=prefer, so the dashboard offers only Postgres until the CA is handled.
     */
    public const OFFERED = [DatastoreKind::Postgres];

    /** @var array<string, string> kind => the major version on DigitalOcean */
    public const VERSIONS = ['mysql' => '8', 'postgres' => '17'];

    public const PRICING_URL = 'https://www.digitalocean.com/pricing/managed-databases';

    private const ENGINES = ['mysql' => 'mysql', 'postgres' => 'pg'];

    /** Only a cluster that opencloud created on DigitalOcean has the VPC and the id that the firewall rule needs. */
    public static function availableOn(Cluster $cluster): bool
    {
        return $cluster->kind === ClusterKind::Created
            && $cluster->provider === 'digitalocean'
            && $cluster->provider_cluster_id !== null
            && ProviderCredential::query()->where('provider', 'digitalocean')->exists();
    }

    public static function stack(DatastoreAttachment $attachment, string $token): Stack
    {
        $datastore = $attachment->datastore;
        $cluster = $datastore->cluster;

        return new Stack('digitalocean/database', "database-{$datastore->id}", [
            'name' => "{$cluster->name}-{$datastore->kind->value}-{$datastore->id}",
            'engine' => self::ENGINES[$datastore->kind->value],
            'engine_version' => $datastore->version,
            'size' => config('opencloud.managed_database_size'),
            'region' => $cluster->region,
            'vpc_id' => $cluster->provider_vpc_id,
            'kubernetes_cluster_id' => $cluster->provider_cluster_id,
            'database' => $attachment->database_name,
            'user' => $attachment->username,
        ], ['DIGITALOCEAN_TOKEN' => $token]);
    }

    /**
     * The same Laravel env keys as the standard engines of the kind.
     *
     * @param  array<string, mixed>  $outputs  the outputs of tofu/digitalocean/database
     * @return array<string, array{0: string, 1: bool}> key => [value, is secret]
     */
    public static function env(DatastoreKind $kind, array $outputs): array
    {
        return [
            'DB_CONNECTION' => [$kind === DatastoreKind::Postgres ? 'pgsql' : 'mysql', false],
            'DB_HOST' => [(string) $outputs['host'], false],
            'DB_PORT' => [(string) $outputs['port'], false],
            'DB_DATABASE' => [(string) $outputs['database'], false],
            'DB_USERNAME' => [(string) $outputs['user'], false],
            'DB_PASSWORD' => [(string) $outputs['password'], true],
        ];
    }
}

<?php

namespace App\Domains\Datastores;

use App\Domains\Clusters\DigitalOceanCluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Tofu\Runner;
use App\Support\Tofu\Stack;

/**
 * A DigitalOcean managed MySQL or Postgres for one environment. tofu/digitalocean/database creates one database
 * cluster with one database and one user.
 */
final class ManagedDatabase implements ManagedStack
{
    public const PRICING_URL = 'https://www.digitalocean.com/pricing/managed-databases';

    /**
     * DigitalOcean MySQL needs TLS with DigitalOcean's own CA, and a stock Laravel app does not load that CA yet.
     * Postgres connects with sslmode=prefer, so the dashboard offers only Postgres until the CA is handled.
     */
    public const MYSQL_NOT_OFFERED = 'A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.';

    /** @var array<string, string> kind => the major version on DigitalOcean */
    private const VERSIONS = ['mysql' => '8', 'postgres' => '17'];

    private const ENGINES = ['mysql' => 'mysql', 'postgres' => 'pg'];

    public function __construct(private DatastoreKind $kind) {}

    public function version(): string
    {
        return self::VERSIONS[$this->kind->value];
    }

    public function names(Environment $environment, ?string $name): array
    {
        return DatabaseNames::names($environment, $name ?? ResourceFamily::FIRST_NAME);
    }

    public function apply(DatastoreAttachment $attachment): array
    {
        $outputs = app(Runner::class)->apply($this->stack($attachment), $attachment->datastore);

        return ['host' => (string) $outputs['host'], 'port' => (int) $outputs['port'], 'password' => (string) $outputs['password']];
    }

    public function destroy(DatastoreAttachment $attachment): void
    {
        app(Runner::class)->destroy($this->stack($attachment), $attachment->datastore);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return SqlConnection::of($attachment);
    }

    private function stack(DatastoreAttachment $attachment): Stack
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
        ], ['DIGITALOCEAN_TOKEN' => DigitalOceanCluster::token()]);
    }
}

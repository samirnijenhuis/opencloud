<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;

/**
 * The managed arm of the registry: a resource that a cloud provider runs and opencloud creates with OpenTofu. Each row
 * names the stack, the right of the DigitalOcean key that the stack needs, and the capabilities.
 */
final class ManagedResources
{
    public static function find(DatastoreKind $kind): ?Provisioner
    {
        $database = 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.';
        $backedUp = 'DigitalOcean backs up a managed database itself.';

        return match ($kind) {
            DatastoreKind::Postgres => new ManagedResource($kind, new ManagedDatabase($kind),
                right: 'database',
                refusal: $database,
                capabilities: new Capabilities($backedUp),
            ),
            DatastoreKind::Mysql => new ManagedResource($kind, new ManagedDatabase($kind),
                right: 'database',
                refusal: $database,
                capabilities: new Capabilities($backedUp, notOffered: ManagedDatabase::MYSQL_NOT_OFFERED),
            ),
            default => null,
        };
    }

    /** Why the managed source cannot serve a kind that find() does not know. */
    public static function unsupported(): string
    {
        return 'DigitalOcean manages MySQL and Postgres only.';
    }
}

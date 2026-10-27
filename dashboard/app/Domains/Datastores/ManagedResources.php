<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;

/**
 * The managed arm of the registry: a resource that a cloud provider runs and opencloud creates with OpenTofu. Each row
 * names the stack, the right of the DigitalOcean key that the stack needs, and the capabilities.
 */
final class ManagedResources
{
    public static function find(DatastoreKind $kind): ?Provisioner
    {
        $database = 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the database right.';
        $backedUp = 'DigitalOcean backs up a managed database itself.';

        return match ($kind) {
            DatastoreKind::POSTGRES => new ManagedResource($kind, new ManagedDatabase($kind),
                right: 'database',
                refusal: $database,
                capabilities: new Capabilities($backedUp),
            ),
            DatastoreKind::MYSQL => new ManagedResource($kind, new ManagedDatabase($kind),
                right: 'database',
                refusal: $database,
                capabilities: new Capabilities($backedUp, notOffered: ManagedDatabase::MYSQL_NOT_OFFERED),
            ),
            DatastoreKind::VALKEY => new ManagedResource($kind, new ManagedValkey,
                right: 'database',
                refusal: 'Managed Valkey needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the database right.',
                capabilities: new Capabilities('DigitalOcean does not back up Valkey, and opencloud does not either. Keep only data that the app can make again.', label: 'Managed Valkey'),
            ),
            DatastoreKind::RUSTFS => new ManagedResource($kind, new ManagedBucket,
                right: 'spaces_key',
                refusal: 'A Spaces bucket needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key with the spaces_key right.',
                capabilities: new Capabilities('The bucket is at DigitalOcean and not on the server, so the nightly job does not copy it.', label: 'Spaces bucket'),
            ),
            default => null,
        };
    }

    /** Why the managed source cannot serve a kind that find() does not know. */
    public static function unsupported(): string
    {
        return 'Opencloud cannot create this kind at DigitalOcean yet.';
    }
}

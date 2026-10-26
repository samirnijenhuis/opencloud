<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;

/**
 * The managed arm of the registry: a resource that a cloud provider runs and opencloud creates with OpenTofu.
 */
final class ManagedResources
{
    public static function find(DatastoreKind $kind): ?Provisioner
    {
        return match ($kind) {
            DatastoreKind::Postgres => new ManagedDatabase($kind),
            DatastoreKind::Mysql => new ManagedDatabase($kind, notOffered: ManagedDatabase::MYSQL_NOT_OFFERED),
            default => null,
        };
    }

    /** Why the managed source cannot serve a kind that find() does not know. */
    public static function unsupported(): string
    {
        return ManagedDatabase::KINDS_ONLY;
    }
}

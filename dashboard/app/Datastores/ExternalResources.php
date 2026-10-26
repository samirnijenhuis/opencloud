<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;

/**
 * The external arm of the registry: a resource that the user runs. Opencloud only stores its address and writes the
 * env values.
 */
final class ExternalResources
{
    /** No default arm, so a new kind gets a label here before it runs. */
    public static function find(DatastoreKind $kind): ?Provisioner
    {
        return match ($kind) {
            DatastoreKind::Mysql => new ExternalProvisioner($kind, 'External MySQL'),
            DatastoreKind::Postgres => new ExternalProvisioner($kind, 'External Postgres'),
            DatastoreKind::Valkey => new ExternalProvisioner($kind, 'External Redis or Valkey'),
            DatastoreKind::Rustfs => new ExternalProvisioner($kind, 'External S3 bucket'),
        };
    }

    /** Why the external source cannot serve a kind that find() does not know. */
    public static function unsupported(): string
    {
        return 'Opencloud cannot connect this kind from outside yet.';
    }
}

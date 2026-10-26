<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;

/**
 * The external arm of the registry: a resource that the user runs. Opencloud only stores its address and writes the
 * env values.
 */
final class ExternalResources
{
    public static function find(DatastoreKind $kind): ?Provisioner
    {
        return null;
    }

    /** Why the external source cannot serve a kind that find() does not know. */
    public static function unsupported(): string
    {
        return 'Opencloud cannot connect this kind from outside yet.';
    }
}

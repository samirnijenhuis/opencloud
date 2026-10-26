<?php

namespace App\Datastores;

use App\Models\Datastore;
use App\Models\DatastoreAttachment;

/**
 * The registry of the resources that opencloud can attach, keyed by kind and source.
 */
final class Provisioners
{
    /**
     * The provisioner of a stored datastore.
     *
     * Contract body: only connection() works, and it goes through Engines::for() by kind, exactly like AttachmentEnv
     * did before. Workstream C replaces this body with the table and keeps the signature.
     */
    public static function of(Datastore $datastore): Provisioner
    {
        return new readonly class(Engines::for($datastore->kind)) implements Provisioner
        {
            public function __construct(private Engine $engine) {}

            public function connection(DatastoreAttachment $attachment): ?Connection
            {
                return $this->engine->connection($attachment);
            }
        };
    }
}

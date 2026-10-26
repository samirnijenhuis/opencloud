<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Models\DatastoreAttachment;

/**
 * The dumps of a database on a local server: the SQL engine runs mysqldump or pg_dump in the server pod. Only
 * LocalProvisioner::database() creates one, so it never gets a managed attachment.
 */
final readonly class InClusterDumps implements DatabaseDumps
{
    public function __construct(private SqlEngine $engine) {}

    public function dump(DatastoreAttachment $attachment, mixed $sink): void
    {
        $this->engine->dump(Kubectl::for($attachment->datastore->cluster), $attachment, $sink);
    }

    public function restore(DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        $this->engine->restore(Kubectl::for($attachment->datastore->cluster), $attachment, $gzipDump);
    }
}

<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Models\DatastoreAttachment;

/**
 * The dump and the restore of one database. Only Capabilities gives one out, so a caller that has it knows that
 * opencloud can dump this attachment. LocalSql is the only implementation.
 */
interface DatabaseDumps
{
    /**
     * Streams a gzip dump of the database into $sink.
     *
     * @param  resource  $sink
     */
    public function dump(DatastoreAttachment $attachment, mixed $sink): void;

    /**
     * Replaces the database with a gzip dump. Tables from after the dump do not stay behind.
     *
     * @param  resource  $gzipDump
     */
    public function restore(DatastoreAttachment $attachment, mixed $gzipDump): void;
}

<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Models\DatastoreAttachment;

/**
 * A datastore with one database per attachment, which the nightly backup dumps.
 */
interface SqlEngine extends Engine
{
    /**
     * Streams a gzip dump of the database into $sink.
     *
     * @param  resource  $sink
     */
    public function dump(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $sink): void;

    /**
     * Replaces the database with a gzip dump. Tables from after the dump do not stay behind.
     *
     * @param  resource  $gzipDump
     */
    public function restore(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void;
}

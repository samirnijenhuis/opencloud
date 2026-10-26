<?php

namespace App\Datastores;

use App\Models\DatastoreAttachment;

/**
 * Everything opencloud does for one kind from one source. Provisioners::of() gives the one of a stored datastore.
 * Workstream C adds the lifecycle methods. Workstream B calls only connection().
 */
interface Provisioner
{
    /** How the app reaches it, or null while that is not known yet (a managed database before its apply). */
    public function connection(DatastoreAttachment $attachment): ?Connection;
}

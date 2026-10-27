<?php

namespace App\Domains\Datastores\Enums;

/**
 * The provisioning state of a datastore server and of an attachment. A failed one keeps its error and can run again.
 * Only a managed datastore is ever destroying, while OpenTofu destroys it.
 */
enum DatastoreStatus: string
{
    case PENDING = 'pending';
    case PROVISIONING = 'provisioning';
    case READY = 'ready';
    case FAILED = 'failed';
    case DESTROYING = 'destroying';
}

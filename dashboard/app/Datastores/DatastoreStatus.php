<?php

namespace App\Datastores;

/** The provisioning state of a datastore server and of an attachment. A failed one keeps its error and can run again. */
enum DatastoreStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
}

<?php

namespace App\Clusters;

/** The state of the opencloud parts on a cluster. A failed cluster keeps its error, and "Reinstall parts" runs the install again. */
enum ClusterStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Ready = 'ready';
    case Failed = 'failed';
}

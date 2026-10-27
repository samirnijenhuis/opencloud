<?php

namespace App\Domains\Clusters\Enums;

enum ClusterKind: string
{
    case LOCAL = 'local';
    case CONNECTED = 'connected';
    case CREATED = 'created';
}

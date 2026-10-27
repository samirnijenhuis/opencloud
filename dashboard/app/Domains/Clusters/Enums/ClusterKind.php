<?php

namespace App\Domains\Clusters\Enums;

enum ClusterKind: string
{
    case Local = 'local';
    case Connected = 'connected';
    case Created = 'created';
}

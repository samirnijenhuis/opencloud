<?php

namespace App\Enums;

enum ClusterKind: string
{
    case Local = 'local';
    case Connected = 'connected';
    case Created = 'created';
}

<?php

namespace App\Domains\Datastores\Enums;

enum DatastoreSource: string
{
    case STANDARD = 'standard';
    case MANAGED = 'managed';
    case EXTERNAL = 'external';
}

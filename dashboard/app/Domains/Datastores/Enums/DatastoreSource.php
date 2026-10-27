<?php

namespace App\Domains\Datastores\Enums;

enum DatastoreSource: string
{
    case Standard = 'standard';
    case Managed = 'managed';
    case External = 'external';
}

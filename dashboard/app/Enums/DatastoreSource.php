<?php

namespace App\Enums;

enum DatastoreSource: string
{
    case Standard = 'standard';
    case Managed = 'managed';
    case External = 'external';
}

<?php

namespace App\Domains\Environments\Enums;

enum EnvVarSource: string
{
    case USER = 'user';
    case DATASTORE = 'datastore';
}

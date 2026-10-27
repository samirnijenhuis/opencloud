<?php

namespace App\Domains\Environments\Enums;

enum EnvVarSource: string
{
    case User = 'user';
    case Datastore = 'datastore';
}

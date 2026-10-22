<?php

namespace App\Enums;

enum EnvVarSource: string
{
    case User = 'user';
    case Datastore = 'datastore';
}

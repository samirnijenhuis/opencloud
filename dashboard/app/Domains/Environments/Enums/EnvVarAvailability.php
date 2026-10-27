<?php

namespace App\Domains\Environments\Enums;

enum EnvVarAvailability: string
{
    case Runtime = 'runtime';
    case Build = 'build';
    case Both = 'both';
}

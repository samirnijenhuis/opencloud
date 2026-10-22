<?php

namespace App\Enums;

enum EnvVarAvailability: string
{
    case Runtime = 'runtime';
    case Build = 'build';
    case Both = 'both';
}

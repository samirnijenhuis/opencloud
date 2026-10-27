<?php

namespace App\Domains\Environments\Enums;

enum EnvVarAvailability: string
{
    case RUNTIME = 'runtime';
    case BUILD = 'build';
    case BOTH = 'both';
}

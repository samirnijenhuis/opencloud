<?php

namespace App\Enums;

enum ApplicationType: string
{
    case Laravel = 'laravel';
    case Dockerfile = 'dockerfile';
}

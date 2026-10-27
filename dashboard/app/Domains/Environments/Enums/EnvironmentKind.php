<?php

namespace App\Domains\Environments\Enums;

enum EnvironmentKind: string
{
    case PERMANENT = 'permanent';
    case PREVIEW = 'preview';
    case PREVIEW_TEMPLATE = 'preview_template';
}

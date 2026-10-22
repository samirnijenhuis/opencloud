<?php

namespace App\Enums;

enum EnvironmentKind: string
{
    case Permanent = 'permanent';
    case Preview = 'preview';
    case PreviewTemplate = 'preview_template';
}

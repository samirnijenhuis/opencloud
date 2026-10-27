<?php

namespace App\Domains\Environments\Enums;

/** The color that tells environments apart in the UI. */
enum EnvironmentColor: string
{
    case Blue = 'blue';
    case Green = 'green';
    case Orange = 'orange';
    case Purple = 'purple';
    case Red = 'red';
    case Yellow = 'yellow';
    case Sky = 'sky';
    case Gray = 'gray';

    public static function defaultFor(EnvironmentKind $kind): self
    {
        return $kind === EnvironmentKind::Permanent ? self::Blue : self::Gray;
    }
}

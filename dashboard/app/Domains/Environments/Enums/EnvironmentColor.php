<?php

namespace App\Domains\Environments\Enums;

/** The color that tells environments apart in the UI. */
enum EnvironmentColor: string
{
    case BLUE = 'blue';
    case GREEN = 'green';
    case ORANGE = 'orange';
    case PURPLE = 'purple';
    case RED = 'red';
    case YELLOW = 'yellow';
    case SKY = 'sky';
    case GRAY = 'gray';

    public static function defaultFor(EnvironmentKind $kind): self
    {
        return $kind === EnvironmentKind::PERMANENT ? self::BLUE : self::GRAY;
    }
}

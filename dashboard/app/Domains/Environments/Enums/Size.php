<?php

namespace App\Domains\Environments\Enums;

enum Size: string
{
    case XS = 'xs';
    case S = 's';
    case M = 'm';
    case L = 'l';

    public function cpuMillicores(): int
    {
        return match ($this) {
            self::XS => 250,
            self::S => 500,
            self::M => 1000,
            self::L => 2000,
        };
    }

    public function memoryMib(): int
    {
        return match ($this) {
            self::XS => 256,
            self::S => 512,
            self::M => 1024,
            self::L => 4096,
        };
    }
}

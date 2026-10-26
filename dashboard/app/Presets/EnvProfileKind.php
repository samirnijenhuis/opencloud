<?php

namespace App\Presets;

use App\Datastores\Env\EnvProfile;
use App\Datastores\Env\GenericProfile;
use App\Datastores\Env\LaravelProfile;

/** The env names that an application reads. A new application gets the profile of its preset, and the user can pick the other. */
enum EnvProfileKind: string
{
    case Laravel = 'laravel';
    case Generic = 'generic';

    public function profile(): EnvProfile
    {
        return match ($this) {
            self::Laravel => new LaravelProfile,
            self::Generic => new GenericProfile,
        };
    }
}

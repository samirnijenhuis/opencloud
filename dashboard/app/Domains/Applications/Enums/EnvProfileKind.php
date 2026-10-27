<?php

namespace App\Domains\Applications\Enums;

use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Env\GenericProfile;
use App\Domains\Datastores\Env\LaravelProfile;

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

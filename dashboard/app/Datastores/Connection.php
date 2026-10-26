<?php

namespace App\Datastores;

use App\Datastores\Env\EnvProfile;
use App\Datastores\Env\EnvValue;

/**
 * How an app reaches one attached resource, without env names. Exactly three classes implement it, one per family:
 * SqlConnection, KeyValueConnection, and BucketConnection. A provisioner gives one, and an env profile names it.
 */
interface Connection
{
    /**
     * Asks the profile for the env values of this connection. Each class calls its own method of the profile, so a
     * profile never tests the class of a connection.
     *
     * @return array<string, EnvValue> key => value, in the order the profile writes them
     */
    public function envFor(EnvProfile $profile): array;
}

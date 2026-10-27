<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;

/**
 * The env of one attachment under the profile of its environment. It is the only code that turns a connection into
 * env rows, and the only code that picks a profile. Attach, the managed apply, and a new default database all write
 * through here, so the rows always match what the provisioner says about the attachment now.
 */
final class AttachmentEnv
{
    /** An attachment without a connection yet (a managed database before its apply) has no rows. */
    public static function write(DatastoreAttachment $attachment): void
    {
        $attachment->envVars()->delete();

        $connection = Provisioners::of($attachment->datastore)->connection($attachment);

        foreach ($connection?->envFor(self::profile($attachment->environment)) ?? [] as $key => $value) {
            $attachment->environment->envVars()->updateOrCreate(['key' => $key, 'source' => EnvVarSource::Datastore], [
                'value' => $value->value,
                'is_secret' => $value->secret,
                'available_at' => EnvVarAvailability::Runtime,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }
    }

    /** The config line that the canvas shows for a named database that is not the default, or null. */
    public static function configLine(DatastoreAttachment $attachment): ?string
    {
        return $attachment->name !== null && ! $attachment->is_default
            ? self::profile($attachment->environment)->configLine($attachment->datastore->kind, $attachment->name)
            : null;
    }

    private static function profile(Environment $environment): EnvProfile
    {
        return $environment->application->env_profile->profile();
    }
}

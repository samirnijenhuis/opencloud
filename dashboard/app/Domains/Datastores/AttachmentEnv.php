<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Env\EnvValue;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;

/**
 * The env of one attachment under the profiles of its preset. It is the only code that turns a connection into env
 * rows, and the only code that merges the profiles. Attach, the managed apply, a new default database, and each deploy
 * all write through here, so the rows always match what the provisioner says about the attachment now.
 */
final class AttachmentEnv
{
    /** An attachment without a connection yet (a managed database before its apply) has no rows. */
    public static function write(DatastoreAttachment $attachment): void
    {
        $attachment->envVars()->delete();

        $connection = Provisioners::of($attachment->datastore)->connection($attachment);

        foreach ($connection === null ? [] : self::env($connection, $attachment->environment) as $key => $value) {
            $attachment->environment->envVars()->updateOrCreate(['key' => $key, 'source' => EnvVarSource::DATASTORE], [
                'value' => $value->value,
                'is_secret' => $value->secret,
                'available_at' => EnvVarAvailability::RUNTIME,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }
    }

    /** The config line that the canvas shows for a database or a bucket that is not the default, or null. */
    public static function configLine(DatastoreAttachment $attachment): ?string
    {
        return $attachment->name !== null && ! $attachment->is_default
            ? collect(self::profiles($attachment->environment))
                ->map(fn (EnvProfile $profile) => $profile->configLine($attachment->datastore->kind, $attachment->name, $attachment->is_public))
                ->first(fn (?string $line) => $line !== null)
            : null;
    }

    /**
     * The profiles of one preset name the same resource, so a name that two of them give has the same value.
     * AttachmentEnvTest holds that rule.
     *
     * @return array<string, EnvValue>
     */
    private static function env(Connection $connection, Environment $environment): array
    {
        return array_merge(...array_map(fn (EnvProfile $profile) => $connection->envFor($profile), self::profiles($environment)));
    }

    /** @return list<EnvProfile> */
    private static function profiles(Environment $environment): array
    {
        return $environment->application->type->envProfiles();
    }
}

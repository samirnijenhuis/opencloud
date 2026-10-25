<?php

namespace App\Datastores;

use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Models\DatastoreAttachment;

/**
 * Writes the env values of one attachment as env rows with the source datastore. Attach, the managed apply, and a new
 * default database all write through here, so the rows always match what the engine gives for the attachment now.
 */
final class AttachmentEnv
{
    public static function write(DatastoreAttachment $attachment): void
    {
        $attachment->envVars()->delete();

        foreach (Engines::for($attachment->datastore->kind)->env($attachment) as $key => [$value, $isSecret]) {
            $attachment->environment->envVars()->updateOrCreate(['key' => $key, 'source' => EnvVarSource::Datastore], [
                'value' => $value,
                'is_secret' => $isSecret,
                'available_at' => EnvVarAvailability::Runtime,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }
    }
}

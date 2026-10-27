<?php

namespace App\Domains\Datastores\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Members\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetDefaultDatabase
{
    /**
     * Makes the database the default of its environment. It gets the DB_* values, and the old default gets its
     * <NAME>_DB_URL value. The app reads the change on the next deploy.
     *
     * @throws InvalidArgumentException for an attachment that is not a MySQL or Postgres database with a name
     */
    public function execute(DatastoreAttachment $attachment, User $by): void
    {
        if ($attachment->name === null) {
            throw new InvalidArgumentException('Only a MySQL or Postgres database can be the default.');
        }

        DB::transaction(function () use ($attachment, $by) {
            $previous = DatastoreAttachment::query()->where('environment_id', $attachment->environment_id)->where('is_default', true)->first();

            if ($previous?->is($attachment)) {
                return;
            }

            // One default per environment is a unique index, so the old default lets go first.
            $previous?->update(['is_default' => false]);
            $attachment->update(['is_default' => true]);

            foreach (array_filter([$previous, $attachment]) as $changed) {
                AttachmentEnv::write($changed);
            }

            AuditEvent::record($by, 'datastore.default_changed', $attachment, [
                'environment_id' => $attachment->environment_id,
                'name' => $attachment->name,
                'previous' => $previous?->name,
            ]);
        });
    }
}

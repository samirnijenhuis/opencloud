<?php

namespace App\Domains\Datastores\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Members\Models\User;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

class SetDefaultAttachment
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Makes the database or the bucket the default of its family in the environment. It gets the default env values,
     * and the old default gets the env values of a named one. The app reads the change on the next deploy.
     *
     * @throws InvalidArgumentException for an attachment that is not a database or a bucket with a name
     */
    public function execute(DatastoreAttachment $attachment, User $by): void
    {
        if ($attachment->name === null) {
            throw new InvalidArgumentException('Only a database or a bucket can be the default.');
        }

        $this->db->transaction(function () use ($attachment, $by) {
            $previous = DatastoreAttachment::query()
                ->where('environment_id', $attachment->environment_id)
                ->where('family', $attachment->family)
                ->where('is_default', true)
                ->first();

            if ($previous?->is($attachment)) {
                return;
            }

            // One default per family and environment is a unique index, so the old default lets go first.
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

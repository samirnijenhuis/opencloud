<?php

namespace App\Actions;

use App\Datastores\DatastoreStatus;
use App\Enums\DatastoreSource;
use App\Jobs\RevokeDatastoreAccess;
use App\Jobs\TearDownManagedDatabase;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DetachDatastore
{
    /**
     * Removes the attachment, its env values, and its database user, access key, or Valkey server.
     * The database, the bucket, and the Valkey volume stay, so a later attach of the same kind finds the data again.
     * A managed database is destroyed with OpenTofu instead, see destroyManaged().
     */
    public function handle(DatastoreAttachment $attachment, User $by): void
    {
        if ($attachment->datastore->source === DatastoreSource::Managed) {
            $this->destroyManaged($attachment, $by);

            return;
        }

        $this->remove($attachment, $by, destroyData: false);
    }

    /** Also removes the database, the bucket, or the Valkey volume. For a preview environment that goes away. */
    public function destroy(DatastoreAttachment $attachment, ?User $by): void
    {
        if ($attachment->datastore->source === DatastoreSource::Managed) {
            $this->destroyManaged($attachment, $by);

            return;
        }

        $this->remove($attachment, $by, destroyData: true);
    }

    /**
     * A managed database belongs to this environment only, so detach destroys it and its data on DigitalOcean.
     * The env values go now. The records stay until the destroy succeeds, so a failed destroy shows its error.
     */
    private function destroyManaged(DatastoreAttachment $attachment, ?User $by): void
    {
        $datastore = $attachment->datastore;

        DB::transaction(function () use ($attachment, $by, $datastore) {
            $attachment->envVars()->delete();
            $datastore->update(['status' => DatastoreStatus::Destroying, 'error' => null]);

            AuditEvent::record($by, 'datastore.destroy_requested', $attachment->environment, [
                'datastore_id' => $datastore->id,
                'kind' => $datastore->kind->value,
                'source' => 'managed',
            ]);

            TearDownManagedDatabase::dispatch($datastore, Arr::except($attachment->getAttributes(), ['password']))->afterCommit();
        });
    }

    private function remove(DatastoreAttachment $attachment, ?User $by, bool $destroyData): void
    {
        $datastore = $attachment->datastore;
        $environment = $attachment->environment;

        DB::transaction(function () use ($attachment, $by, $destroyData, $datastore, $environment) {
            // The env values of the attachment go with it through the foreign key.
            $attachment->delete();

            AuditEvent::record($by, $destroyData ? 'datastore.destroyed' : 'datastore.detached', $environment, [
                'datastore_id' => $datastore->id,
                'kind' => $datastore->kind->value,
            ]);

            RevokeDatastoreAccess::dispatch($datastore, Arr::except($attachment->getAttributes(), ['password']), $destroyData)->afterCommit();
        });
    }
}

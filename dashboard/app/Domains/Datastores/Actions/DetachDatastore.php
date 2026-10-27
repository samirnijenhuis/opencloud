<?php

namespace App\Domains\Datastores\Actions;

use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Members\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DetachDatastore
{
    /**
     * Removes the attachment, its env values, and its access. A local database, bucket, or Valkey volume stays, so a
     * later attach of the same kind and name finds the data again. A managed database is destroyed. An external
     * resource loses only its rows, and its server keeps the data.
     *
     * @throws InvalidArgumentException for the default database while the environment has other databases
     */
    public function execute(DatastoreAttachment $attachment, User $by): void
    {
        DB::transaction(function () use ($attachment, $by) {
            $attachment->refresh();

            if ($attachment->is_default && $attachment->environment->datastoreAttachments()->whereKeyNot($attachment->id)->whereNotNull('name')->exists()) {
                throw new InvalidArgumentException('Make another database the default first.');
            }

            Provisioners::of($attachment->datastore)->detach($attachment, $by, destroyData: false);
        });
    }
}

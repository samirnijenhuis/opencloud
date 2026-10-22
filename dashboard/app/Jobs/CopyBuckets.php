<?php

namespace App\Jobs;

use App\Backups\BackupDisk;
use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts the in-cluster Job that copies every bucket of one RustFS server to the backup target. Without a target there
 * is nowhere safer to copy to, so the job does nothing.
 */
class CopyBuckets implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Datastore $datastore) {}

    public function handle(): void
    {
        $target = BackupDisk::target();

        if ($target === null) {
            return;
        }

        Kubectl::for($this->datastore->cluster)->apply(DatastoreManifests::copyBuckets($this->datastore, $target, now()->format('Ymd')));
    }
}

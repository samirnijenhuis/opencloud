<?php

namespace Tests\Domains;

use App\Domains\Backups\Jobs\BackupDatabase;
use App\Domains\Backups\Jobs\RestoreDatabase;
use App\Domains\Backups\Models\Backup;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Models\DatastoreAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongJobQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_dumps_and_restores_run_on_the_builds_queue_but_provisioning_does_not(): void
    {
        $attachment = DatastoreAttachment::factory()->create();
        $backup = Backup::factory()->create();

        $this->assertSame('builds', (new BackupDatabase($attachment))->queue);
        $this->assertSame('builds', (new RestoreDatabase($backup, null))->queue);
        $this->assertNull((new ProvisionDatastoreAttachment($attachment))->queue, 'A deployment waits for provisioning, so provisioning must not queue behind it.');
    }
}

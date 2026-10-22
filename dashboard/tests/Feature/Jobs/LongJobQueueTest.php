<?php

namespace Tests\Feature\Jobs;

use App\Jobs\BackupDatabase;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\RestoreDatabase;
use App\Models\Backup;
use App\Models\DatastoreAttachment;
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

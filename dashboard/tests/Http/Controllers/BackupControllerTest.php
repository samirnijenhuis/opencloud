<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Actions\BackupNow;
use App\Domains\Backups\Actions\RestoreBackup;
use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Backups\Jobs\BackupDatabase;
use App\Domains\Backups\Jobs\RestoreDatabase;
use App\Domains\Backups\Models\Backup;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private DatastoreAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        $environment = $this->makeEnvironment();
        $datastore = Datastore::factory()->create(['cluster_id' => $this->makeCluster()->id, 'kind' => DatastoreKind::Postgres, 'status' => DatastoreStatus::Ready->value]);
        $this->attachment = DatastoreAttachment::factory()->create(['datastore_id' => $datastore->id, 'environment_id' => $environment->id, 'database_name' => 'shop_production', 'status' => DatastoreStatus::Ready]);
    }

    private function backup(array $attributes = []): Backup
    {
        return Backup::factory()->create([
            'datastore_id' => $this->attachment->datastore_id,
            'datastore_attachment_id' => $this->attachment->id,
            'database_name' => 'shop_production',
            ...$attributes,
        ]);
    }

    public function test_the_page_lists_the_newest_backups_and_the_latest_restore_result(): void
    {
        $old = $this->backup(['size' => 2048]);
        $new = $this->backup(['disk' => 's3', 'size' => 4096]);
        $this->backup(['status' => BackupStatus::Running, 'size' => null]);
        AuditEvent::record(User::factory()->create(), 'backup.restored', $old, ['database_name' => 'shop_production']);
        AuditEvent::record(User::factory()->create(), 'backup.restore_failed', $new, ['database_name' => 'shop_production', 'error' => 'The dump is damaged.']);

        $this->actingAs(User::factory()->create())->get('/environments/'.$this->attachment->environment_id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('datastores.attachments.0.backups', 3)
                ->where('datastores.attachments.0.backups.0.status', 'running')
                ->where('datastores.attachments.0.backups.1.location', 's3')
                ->where('datastores.attachments.0.backups.1.size', 4096)
                ->where('datastores.attachments.0.backups.2.location', 'local')
                ->where('datastores.attachments.0.restore.status', 'failed')
                ->where('datastores.attachments.0.restore.error', 'The dump is damaged.')
                ->where('can.restore', false)
                ->where('datastores.polling', true));
    }

    public function test_a_restore_in_progress_makes_the_page_poll(): void
    {
        $backup = $this->backup();
        AuditEvent::record(User::factory()->create(), 'backup.restore_requested', $backup, ['database_name' => 'shop_production']);

        $this->actingAs(User::factory()->owner()->create())->get('/environments/'.$this->attachment->environment_id)
            ->assertInertia(fn (Assert $page) => $page->where('datastores.attachments.0.restore.status', 'running')->where('datastores.polling', true)->where('can.restore', true));
    }

    public function test_a_developer_cannot_back_up_or_restore_in_a_protected_environment(): void
    {
        $backup = $this->backup();
        $this->mock(BackupNow::class)->shouldNotReceive('execute');
        $this->mock(RestoreBackup::class)->shouldNotReceive('execute');
        $developer = User::factory()->create();

        $this->actingAs($developer)->post("/datastore-attachments/{$this->attachment->id}/backups")->assertForbidden();
        $this->actingAs($developer)->post("/backups/{$backup->id}/restore", ['confirm' => true])->assertForbidden();
    }

    public function test_a_developer_cannot_restore_even_in_an_unprotected_environment(): void
    {
        $this->attachment->environment->update(['is_protected' => false]);
        $backup = $this->backup();
        $this->mock(RestoreBackup::class)->shouldNotReceive('execute');

        $this->actingAs(User::factory()->create())->post("/backups/{$backup->id}/restore", ['confirm' => true])->assertForbidden();
    }

    public function test_owner_and_admin_start_a_backup_through_the_action(): void
    {
        foreach ([User::factory()->owner()->create(), User::factory()->admin()->create()] as $user) {
            $this->mock(BackupNow::class)
                ->shouldReceive('execute')
                ->once()
                ->withArgs(fn (DatastoreAttachment $given, User $by) => $given->is($this->attachment) && $by->is($user));

            $this->actingAs($user)->post("/datastore-attachments/{$this->attachment->id}/backups")->assertRedirect();
        }
    }

    public function test_a_backup_refusal_is_shown(): void
    {
        $this->mock(BackupNow::class)->shouldReceive('execute')->andThrow(new InvalidArgumentException('No database.'));

        $this->actingAs(User::factory()->owner()->create())->post("/datastore-attachments/{$this->attachment->id}/backups")->assertSessionHasErrors(['backup' => 'No database.']);
    }

    public function test_owner_and_admin_restore_through_the_action(): void
    {
        $backup = $this->backup();

        foreach ([User::factory()->owner()->create(), User::factory()->admin()->create()] as $user) {
            $this->mock(RestoreBackup::class)
                ->shouldReceive('execute')
                ->once()
                ->withArgs(fn (Backup $given, User $by) => $given->is($backup) && $by->is($user));

            $this->actingAs($user)->post("/backups/{$backup->id}/restore", ['confirm' => true])->assertRedirect();
        }
    }

    public function test_a_restore_needs_the_confirmation(): void
    {
        $backup = $this->backup();
        $this->mock(RestoreBackup::class)->shouldNotReceive('execute');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/backups/{$backup->id}/restore")->assertSessionHasErrors('confirm');
        $this->actingAs($owner)->post("/backups/{$backup->id}/restore", ['confirm' => false])->assertSessionHasErrors('confirm');
    }

    public function test_a_restore_refusal_is_shown(): void
    {
        $backup = $this->backup(['status' => BackupStatus::Failed]);

        $this->actingAs(User::factory()->owner()->create())->post("/backups/{$backup->id}/restore", ['confirm' => true])
            ->assertSessionHasErrors(['backup' => 'Only a finished backup can be restored.']);
    }

    public function test_a_managed_database_is_not_backed_up_by_a_hand_made_request(): void
    {
        Queue::fake();
        $managed = $this->managedAttachment();

        $this->actingAs(User::factory()->owner()->create())->post(route('backups.store', $managed))
            ->assertSessionHasErrors(['backup' => 'DigitalOcean backs up a managed database itself.']);

        Queue::assertNotPushed(BackupDatabase::class);
        $this->assertSame(0, AuditEvent::query()->where('action', 'backup.requested')->count());
    }

    public function test_a_backup_of_a_managed_database_is_not_restored_by_a_hand_made_request(): void
    {
        Queue::fake();
        $managed = $this->managedAttachment();
        $backup = Backup::factory()->create(['datastore_id' => $managed->datastore_id, 'datastore_attachment_id' => $managed->id, 'database_name' => $managed->database_name]);

        $this->actingAs(User::factory()->owner()->create())->post(route('backups.restore', $backup), ['confirm' => true])
            ->assertSessionHasErrors(['backup' => 'DigitalOcean backs up a managed database itself.']);

        Queue::assertNotPushed(RestoreDatabase::class);
        $this->assertSame(0, AuditEvent::query()->where('action', 'backup.restore_requested')->count());
    }

    private function managedAttachment(): DatastoreAttachment
    {
        $datastore = Datastore::factory()->create(['cluster_id' => $this->attachment->datastore->cluster_id, 'kind' => DatastoreKind::Postgres, 'source' => DatastoreSource::Managed, 'status' => DatastoreStatus::Ready->value]);

        return DatastoreAttachment::factory()->create(['datastore_id' => $datastore->id, 'environment_id' => $this->attachment->environment_id, 'database_name' => 'managed_production', 'status' => DatastoreStatus::Ready]);
    }
}

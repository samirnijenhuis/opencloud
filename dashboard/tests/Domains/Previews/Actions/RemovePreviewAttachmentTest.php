<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Previews\Actions\RemovePreviewAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemovePreviewAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Environment $template;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Environment::factory()->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null, 'preview_attachments' => [
            ['kind' => 'mysql', 'name' => 'main', 'is_default' => true],
            ['kind' => 'postgres', 'name' => 'reports', 'is_default' => false],
            ['kind' => 'valkey', 'name' => null, 'is_default' => false],
        ]]);
        $this->owner = User::factory()->owner()->create();
    }

    public function test_an_entry_leaves_the_list_and_a_second_remove_changes_nothing(): void
    {
        $remove = app(RemovePreviewAttachment::class);

        $remove->execute($this->template, $this->owner, DatastoreKind::POSTGRES, 'reports');
        $remove->execute($this->template, $this->owner, DatastoreKind::VALKEY, null);
        $remove->execute($this->template, $this->owner, DatastoreKind::POSTGRES, 'reports');

        $this->assertSame([['kind' => 'mysql', 'name' => 'main', 'is_default' => true]], $this->template->fresh()->preview_attachments);
        $this->assertSame([
            ['preview_template.attachment_removed', $this->owner->id, $this->template->id, ['kind' => 'postgres', 'name' => 'reports']],
            ['preview_template.attachment_removed', $this->owner->id, $this->template->id, ['kind' => 'valkey']],
        ], AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->user_id, $event->subject_id, $event->context])->all());
    }

    public function test_the_default_database_goes_last(): void
    {
        $remove = app(RemovePreviewAttachment::class);

        try {
            $remove->execute($this->template, $this->owner, DatastoreKind::MYSQL, 'main');
            $this->fail('The default database left while the list has another database.');
        } catch (ValidationException $e) {
            $this->assertSame(['detach' => ['This is the default database of each preview. Remove the other databases first.']], $e->errors());
        }
        $this->assertCount(3, $this->template->fresh()->preview_attachments);

        $remove->execute($this->template, $this->owner, DatastoreKind::POSTGRES, 'reports');
        $remove->execute($this->template, $this->owner, DatastoreKind::MYSQL, 'main');

        $this->assertSame([['kind' => 'valkey', 'name' => null, 'is_default' => false]], $this->template->fresh()->preview_attachments);
    }
}

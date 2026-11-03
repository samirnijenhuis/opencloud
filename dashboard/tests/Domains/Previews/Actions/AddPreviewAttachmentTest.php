<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Previews\Actions\AddPreviewAttachment;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddPreviewAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Environment $template;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Environment::factory()->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        $this->owner = User::factory()->owner()->create();
    }

    public function test_the_list_takes_each_kind_with_the_name_rules_and_the_template_gets_no_resource(): void
    {
        Queue::fake();
        $kubectl = FakeKubectl::install();
        $add = app(AddPreviewAttachment::class);

        $add->execute($this->template, $this->owner, DatastoreKind::MYSQL);
        $add->execute($this->template, $this->owner, DatastoreKind::POSTGRES, 'reports');
        $add->execute($this->template, $this->owner, DatastoreKind::VALKEY);
        $add->execute($this->template, $this->owner, DatastoreKind::RUSTFS);
        $add->execute($this->template, $this->owner, DatastoreKind::RUSTFS, 'uploads');

        $this->assertSame([
            ['kind' => 'mysql', 'name' => 'main', 'is_default' => true],
            ['kind' => 'postgres', 'name' => 'reports', 'is_default' => false],
            ['kind' => 'valkey', 'name' => null, 'is_default' => false],
            ['kind' => 'rustfs', 'name' => 'main', 'is_default' => true],
            ['kind' => 'rustfs', 'name' => 'uploads', 'is_default' => false],
        ], $this->template->fresh()->preview_attachments);
        $this->assertSame([0, 0, []], [Datastore::query()->count(), DatastoreAttachment::query()->count(), $kubectl->calls]);
        Queue::assertNothingPushed();
        $this->assertSame([
            ['preview_template.attachment_added', $this->owner->id, $this->template->id, ['kind' => 'mysql', 'name' => 'main']],
            ['preview_template.attachment_added', $this->owner->id, $this->template->id, ['kind' => 'postgres', 'name' => 'reports']],
            ['preview_template.attachment_added', $this->owner->id, $this->template->id, ['kind' => 'valkey']],
            ['preview_template.attachment_added', $this->owner->id, $this->template->id, ['kind' => 'rustfs', 'name' => 'main']],
            ['preview_template.attachment_added', $this->owner->id, $this->template->id, ['kind' => 'rustfs', 'name' => 'uploads']],
        ], AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->user_id, $event->subject_id, $event->context])->all());
    }

    /** @return array<string, array{DatastoreKind, ?string, string, string}> */
    public static function refusals(): array
    {
        return [
            'a second key-value store' => [DatastoreKind::VALKEY, null, 'kind', 'Each preview already gets a valkey datastore.'],
            'a later database without a name' => [DatastoreKind::POSTGRES, null, 'name', 'Each preview already gets a database. Give the new database a name.'],
            'a taken name' => [DatastoreKind::POSTGRES, 'main', 'name', 'Each preview already gets a database with the name main.'],
            'a name that is not valid' => [DatastoreKind::POSTGRES, 'Reports', 'name', 'Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.'],
            'a stock disk of Laravel' => [DatastoreKind::RUSTFS, 's3', 'name', 'Laravel already has a disk with the name s3. Use a different name.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_an_entry_that_breaks_a_rule_is_refused_and_the_list_stays_the_same(DatastoreKind $kind, ?string $name, string $field, string $message): void
    {
        $list = [['kind' => 'mysql', 'name' => 'main', 'is_default' => true], ['kind' => 'valkey', 'name' => null, 'is_default' => false]];
        $this->template->update(['preview_attachments' => $list]);

        try {
            app(AddPreviewAttachment::class)->execute($this->template, $this->owner, $kind, $name);
            $this->fail('The entry was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame([$field => [$message]], $e->errors());
        }

        $this->assertSame($list, $this->template->fresh()->preview_attachments);
        $this->assertSame(0, AuditEvent::count());
    }
}

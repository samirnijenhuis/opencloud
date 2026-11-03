<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class PreviewAttachmentControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private Environment $production;

    private Environment $template;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->production = $this->makeEnvironment();
        $this->template = Environment::factory()->for($this->production->application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
    }

    public function test_a_developer_adds_and_removes_entries_of_the_template_list_and_the_template_gets_no_resource(): void
    {
        $kubectl = FakeKubectl::install();
        $page = "/environments/{$this->template->id}";
        $this->actingAs(User::factory()->create())->from($page);

        $this->post("/environments/{$this->template->id}/preview-attachments", ['kind' => 'mysql', 'source' => 'standard', 'name' => 'main'])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->post("/environments/{$this->template->id}/preview-attachments", ['kind' => 'valkey', 'source' => 'standard', 'name' => null])->assertSessionHasNoErrors();
        $this->post("/environments/{$this->template->id}/preview-attachments", ['kind' => 'rustfs', 'name' => 'main'])->assertSessionHasNoErrors();
        $this->post("/environments/{$this->template->id}/preview-attachments", ['kind' => 'postgres', 'name' => 'main'])
            ->assertSessionHasErrors(['name' => 'Each preview already gets a database with the name main.']);
        $this->delete("/environments/{$this->template->id}/preview-attachments", ['kind' => 'valkey', 'name' => null])->assertRedirect($page)->assertSessionHasNoErrors();

        $this->assertSame([
            ['kind' => 'mysql', 'name' => 'main', 'is_default' => true],
            ['kind' => 'rustfs', 'name' => 'main', 'is_default' => true],
        ], $this->template->fresh()->preview_attachments);
        $this->assertSame([0, []], [DatastoreAttachment::query()->count(), $kubectl->calls]);
        Queue::assertNothingPushed();
    }

    public function test_only_a_user_who_may_change_the_template_changes_its_list_and_no_other_environment_has_one(): void
    {
        $this->template->update(['is_protected' => true, 'preview_attachments' => [['kind' => 'valkey', 'name' => null, 'is_default' => false]]]);
        $this->actingAs(User::factory()->create());

        $this->post("/environments/{$this->template->id}/preview-attachments", ['kind' => 'mysql'])->assertForbidden();
        $this->delete("/environments/{$this->template->id}/preview-attachments", ['kind' => 'valkey'])->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        $this->post("/environments/{$this->production->id}/preview-attachments", ['kind' => 'mysql'])->assertForbidden();
        $this->delete("/environments/{$this->production->id}/preview-attachments", ['kind' => 'mysql', 'name' => 'main'])->assertForbidden();

        $this->assertSame([['kind' => 'valkey', 'name' => null, 'is_default' => false]], $this->template->fresh()->preview_attachments);
        $this->assertSame([], $this->production->fresh()->preview_attachments);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }
}

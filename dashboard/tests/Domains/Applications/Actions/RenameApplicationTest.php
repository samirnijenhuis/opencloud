<?php

namespace Tests\Domains\Applications\Actions;

use App\Domains\Applications\Actions\RenameApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenameApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rename_stores_the_name_and_records_the_old_and_the_new_name(): void
    {
        $admin = User::factory()->admin()->create();
        $application = Application::factory()->create(['name' => 'shop']);

        app(RenameApplication::class)->execute($application, $admin, 'store');

        $this->assertSame('store', $application->fresh()->name);
        $event = AuditEvent::sole();
        $this->assertSame(['application.renamed', ['from' => 'shop', 'to' => 'store'], $admin->id], [$event->action, $event->context, $event->user_id]);
        $this->assertTrue($event->subject->is($application));
    }

    public function test_the_same_name_records_nothing(): void
    {
        $application = Application::factory()->create(['name' => 'shop']);

        app(RenameApplication::class)->execute($application, User::factory()->admin()->create(), 'shop');

        $this->assertSame(0, AuditEvent::count());
    }
}

<?php

namespace Tests\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\SetPrimaryDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class SetPrimaryDomainTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_old_primary_domain_lets_go_and_an_audit_event_records_the_change(): void
    {
        $environment = $this->makeEnvironment();
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com', 'is_primary' => true]);
        $new = Domain::factory()->for($environment)->create(['hostname' => 'www.shop.example.com']);
        Domain::factory()->for($this->makeEnvironment(false, 'staging'))->create(['hostname' => 'staging.example.com', 'is_primary' => true]);
        $admin = User::factory()->admin()->create();

        app(SetPrimaryDomain::class)->execute($new, $admin);

        $this->assertSame(
            ['shop.example.com' => false, 'www.shop.example.com' => true, 'staging.example.com' => true],
            Domain::query()->orderBy('id')->pluck('is_primary', 'hostname')->all(),
        );
        $this->assertSame(
            [['domain.primary_changed', $admin->id, $new->id, ['environment_id' => $environment->id, 'hostname' => 'www.shop.example.com', 'previous' => 'shop.example.com']]],
            AuditEvent::all()->map(fn (AuditEvent $event) => [$event->action, $event->user_id, $event->subject_id, $event->context])->all(),
        );
    }

    public function test_making_the_primary_domain_primary_again_changes_nothing(): void
    {
        $domain = Domain::factory()->for($this->makeEnvironment())->create(['is_primary' => true]);

        app(SetPrimaryDomain::class)->execute($domain, User::factory()->admin()->create());

        $this->assertTrue($domain->fresh()->is_primary);
        $this->assertSame(0, AuditEvent::count());
    }
}

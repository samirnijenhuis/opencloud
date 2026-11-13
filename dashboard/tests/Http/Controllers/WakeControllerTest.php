<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class WakeControllerTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;

    private Environment $preview;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->preview = $this->makeEnvironment(name: 'pr-1', attributes: ['kind' => EnvironmentKind::PREVIEW, 'sleep_after_minutes' => 15]);
        $this->preview->forceFill(['slept_at' => now()->subHour()])->save();
        Domain::factory()->for($this->preview)->create(['hostname' => 'pr-1.example.com']);
    }

    public function test_the_first_request_wakes_the_environment_and_shows_a_starting_page_that_reloads_itself(): void
    {
        $response = $this->get('/_opencloud/wake/pr-1.example.com');

        $response->assertStatus(503)
            ->assertHeader('Retry-After', '3')
            ->assertSee('<meta http-equiv="refresh" content="3">', escape: false)
            ->assertSee('Starting pr-1.example.com');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertNull($this->preview->fresh()->slept_at);
        $this->assertSame(['environment.woke', null], [AuditEvent::sole()->action, AuditEvent::sole()->user_id]);
        Queue::assertPushed(ScaleEnvironment::class, fn (ScaleEnvironment $job) => $job->environment->is($this->preview));
    }

    public function test_a_form_post_wakes_the_environment_without_a_csrf_token(): void
    {
        $this->post('/_opencloud/wake/pr-1.example.com', ['email' => 'a@example.com'])->assertStatus(503);

        $this->assertNull($this->preview->fresh()->slept_at);
    }

    public function test_a_reload_while_the_pods_start_shows_the_starting_page_and_wakes_nothing_again(): void
    {
        $this->get('/_opencloud/wake/pr-1.example.com')->assertStatus(503);
        $this->travel(10)->seconds();

        $this->get('/_opencloud/wake/pr-1.example.com')->assertStatus(503)->assertSee('Starting pr-1.example.com');

        $this->assertSame(1, AuditEvent::count());
        Queue::assertPushed(ScaleEnvironment::class, 1);
    }

    public function test_a_hostname_of_another_environment_never_wakes_this_one(): void
    {
        $production = Environment::factory()->for($this->preview->application)->create(['name' => 'production']);
        Domain::factory()->for($production)->create(['hostname' => 'shop.example.com']);

        $this->get('/_opencloud/wake/shop.example.com')->assertStatus(502);
        $this->get('/_opencloud/wake/unknown.example.com')->assertNotFound();

        $this->assertNotNull($this->preview->fresh()->slept_at);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_an_awake_app_that_does_not_answer_gets_a_plain_502(): void
    {
        $this->preview->forceFill(['slept_at' => null, 'woke_at' => now()->subMinutes(6)])->save();

        $this->get('/_opencloud/wake/pr-1.example.com')
            ->assertStatus(502)
            ->assertSeeText('The app does not answer. Try again in a minute.');

        Queue::assertNothingPushed();
    }
}

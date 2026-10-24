<?php

namespace Tests\Feature\Environments;

use App\Actions\SetEnvironmentColor;
use App\Enums\EnvironmentKind;
use App\Environments\EnvironmentColor;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Http\BuildsEnvironments;
use Tests\TestCase;

class EnvironmentColorTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_a_permanent_environment_starts_blue_and_previews_start_gray(): void
    {
        $colors = collect([EnvironmentKind::Permanent, EnvironmentKind::Preview, EnvironmentKind::PreviewTemplate])
            ->map(fn (EnvironmentKind $kind) => Environment::factory()->create(['kind' => $kind])->fresh()->color)
            ->all();

        $this->assertSame([EnvironmentColor::Blue, EnvironmentColor::Gray, EnvironmentColor::Gray], $colors);
        $this->assertSame(EnvironmentColor::Purple, Environment::factory()->create(['color' => 'purple'])->fresh()->color);
    }

    public function test_the_action_stores_the_color_and_records_the_change_once(): void
    {
        $environment = $this->makeEnvironment();
        $owner = User::factory()->owner()->create();

        app(SetEnvironmentColor::class)->handle($environment, $owner, EnvironmentColor::Red);
        app(SetEnvironmentColor::class)->handle($environment, $owner, EnvironmentColor::Red);

        $this->assertSame('red', $environment->fresh()->getRawOriginal('color'));
        $this->assertSame(
            [['environment.updated', ['changes' => ['color' => ['from' => 'blue', 'to' => 'red']]]]],
            AuditEvent::all()->map(fn (AuditEvent $event) => [$event->action, $event->context])->all(),
        );
    }

    public function test_only_users_who_may_change_the_environment_set_its_color(): void
    {
        $production = $this->makeEnvironment();
        $staging = Environment::factory()->for($production->application)->create(['name' => 'staging', 'is_protected' => false]);
        $developer = User::factory()->create();

        $this->actingAs($developer)->patch("/environments/{$production->id}/color", ['color' => 'red'])->assertForbidden();
        $this->actingAs($developer)->patch("/environments/{$staging->id}/color", ['color' => 'sky'])->assertRedirect();
        $this->actingAs(User::factory()->admin()->create())->patch("/environments/{$production->id}/color", ['color' => 'green'])->assertRedirect();
        $this->actingAs($developer)->patch("/environments/{$staging->id}/color", ['color' => 'pink'])->assertSessionHasErrors('color');

        $this->assertSame([EnvironmentColor::Green, EnvironmentColor::Sky], [$production->fresh()->color, $staging->fresh()->color]);
    }
}

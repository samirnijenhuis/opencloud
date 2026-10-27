<?php

namespace Tests\Feature\Environments;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\RunCommand;
use App\Domains\Environments\Enums\CommandRunStatus;
use App\Domains\Environments\Jobs\ExecuteCommandRun;
use App\Domains\Environments\Models\CommandRun;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Http\BuildsEnvironments;
use Tests\TestCase;

class CommandRunTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const SHA = 'dddddddddddddddddddddddddddddddddddddddd';

    private Environment $production;

    private Deployment $live;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $this->production = $this->makeEnvironment();
        EnvVar::factory()->for($this->production)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);
        $this->live = Deployment::factory()->for($this->production)->create(['status' => DeploymentStatus::Live, 'commit_sha' => self::SHA]);
    }

    public function test_the_action_stores_the_run_records_the_masked_command_and_queues_it_on_the_default_queue(): void
    {
        $owner = User::factory()->owner()->create();

        $run = app(RunCommand::class)->handle($this->production, $owner, '  php artisan tinker --execute="echo \'hunter22\';"  ');

        $this->assertSame(
            [$this->production->id, $owner->id, $this->live->id, 'php artisan tinker --execute="echo \'hunter22\';"', CommandRunStatus::Queued],
            [$run->environment_id, $run->user_id, $run->deployment_id, $run->command, $run->fresh()->status],
        );
        $this->assertSame(
            ['command.started', $owner->id, ['environment_id' => $this->production->id, 'command' => 'php artisan tinker --execute="echo \'********\';"']],
            [AuditEvent::sole()->action, AuditEvent::sole()->user_id, AuditEvent::sole()->context],
        );
        Queue::assertPushed(ExecuteCommandRun::class, fn (ExecuteCommandRun $job) => $job->run->is($run) && $job->queue === null);
    }

    public function test_the_action_refuses_an_environment_without_a_live_deployment(): void
    {
        $this->live->update(['status' => DeploymentStatus::Superseded]);

        try {
            app(RunCommand::class)->handle($this->production, User::factory()->owner()->create(), 'php artisan --version');
            $this->fail('The action should refuse.');
        } catch (ValidationException $e) {
            $this->assertSame(['command' => ['The environment has no live deployment. Deploy it first.']], $e->errors());
        }

        $this->assertSame(0, CommandRun::count());
        Queue::assertNothingPushed();
    }

    public function test_developers_run_commands_only_on_unprotected_environments(): void
    {
        $staging = Environment::factory()->for($this->production->application)->create(['name' => 'staging', 'is_protected' => false]);
        Deployment::factory()->for($staging)->create(['status' => DeploymentStatus::Live]);
        $developer = User::factory()->create();

        $this->actingAs($developer)->post("/environments/{$this->production->id}/command-runs", ['command' => 'php artisan migrate:status'])->assertForbidden();
        $this->actingAs($developer)->post("/environments/{$staging->id}/command-runs", ['command' => 'php artisan migrate:status'])->assertRedirect();
        $this->actingAs(User::factory()->admin()->create())->post("/environments/{$this->production->id}/command-runs", ['command' => 'php artisan about'])->assertRedirect();

        $this->assertSame(
            [[$staging->id, 'php artisan migrate:status'], [$this->production->id, 'php artisan about']],
            CommandRun::orderBy('id')->get()->map(fn (CommandRun $run) => [$run->environment_id, $run->command])->all(),
        );
    }

    public function test_a_json_client_gets_the_new_run_and_bad_input_gets_422(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olga']);

        $response = $this->actingAs($owner)->postJson("/environments/{$this->production->id}/command-runs", ['command' => 'php artisan --version']);

        $response->assertCreated()->assertExactJson(['run' => [
            'id' => CommandRun::sole()->id,
            'command' => 'php artisan --version',
            'status' => 'queued',
            'exit_code' => null,
            'user' => 'Olga',
            'commit_sha' => self::SHA,
            'created_at' => '2026-10-24T12:00:00+00:00',
            'started_at' => null,
            'finished_at' => null,
        ]]);
        $this->actingAs($owner)->postJson("/environments/{$this->production->id}/command-runs", ['command' => '   '])->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->actingAs($owner)->postJson("/environments/{$this->production->id}/command-runs", ['command' => str_repeat('x', 2001)])->assertUnprocessable();
    }

    public function test_the_history_lists_the_20_newest_runs_and_one_run_shows_its_output(): void
    {
        $user = User::factory()->create(['name' => 'Dev']);
        foreach (range(1, 21) as $number) {
            CommandRun::create(['environment_id' => $this->production->id, 'user_id' => $user->id, 'deployment_id' => $this->live->id, 'command' => "echo {$number}"]);
        }
        $newest = CommandRun::latest('id')->first();
        $newest->forceFill([
            'command' => 'echo hunter22',
            'status' => CommandRunStatus::Failed,
            'exit_code' => 3,
            'output' => "hunter22 is ********\n",
            'started_at' => now(),
            'finished_at' => now()->addSeconds(5),
        ])->save();

        $runs = $this->actingAs($user)->getJson("/environments/{$this->production->id}/command-runs")->assertOk()->json('runs');

        $this->assertSame(['echo ********', 'echo 20', 'echo 2'], [$runs[0]['command'], $runs[1]['command'], $runs[19]['command']]);
        $this->assertCount(20, $runs);
        $this->assertArrayNotHasKey('output', $runs[0]);

        $this->actingAs($user)->getJson("/command-runs/{$newest->id}")->assertOk()->assertExactJson(['run' => [
            'id' => $newest->id,
            'command' => 'echo ********',
            'status' => 'failed',
            'exit_code' => 3,
            'user' => 'Dev',
            'commit_sha' => self::SHA,
            'created_at' => '2026-10-24T12:00:00+00:00',
            'started_at' => '2026-10-24T12:00:00+00:00',
            'finished_at' => '2026-10-24T12:00:05+00:00',
            'output' => "hunter22 is ********\n",
        ]]);
    }

    public function test_guests_get_nothing(): void
    {
        $run = CommandRun::create(['environment_id' => $this->production->id, 'deployment_id' => $this->live->id, 'command' => 'ls']);

        $this->getJson("/environments/{$this->production->id}/command-runs")->assertUnauthorized();
        $this->getJson("/command-runs/{$run->id}")->assertUnauthorized();
        $this->postJson("/environments/{$this->production->id}/command-runs", ['command' => 'ls'])->assertUnauthorized();
    }
}

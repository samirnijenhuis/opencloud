<?php

namespace Tests\Http\Controllers;

use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class EnvironmentLogControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_offers_web_and_the_processes_of_the_environment(): void
    {
        $environment = $this->makeEnvironment();
        Process::factory()->for($environment)->create();
        Process::factory()->for($environment)->scheduled()->create();

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}/logs")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('environments/logs')->where('processes', ['web', 'worker', 'scheduler']));
    }

    public function test_the_json_endpoint_returns_masked_lines_of_the_range_from_the_action(): void
    {
        $kubectl = FakeKubectl::install();
        $kubectl->respond('exec', new KubectlResult(0, json_encode(['data' => ['result' => [
            ['stream' => ['pod' => 'reverb-1'], 'values' => [['1793300000000000000', 'password is hunter22']]],
        ]]])));
        $environment = $this->makeEnvironment();
        Process::factory()->for($environment)->create(['name' => 'reverb', 'command' => 'php artisan reverb:start']);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=reverb&from=2026-10-29T18:00:00Z&to=2026-10-29T19:00:00Z&search=password")
            ->assertOk()
            ->assertExactJson([
                'lines' => [['time' => '2026-10-29T18:53:20.000Z', 'pod' => 'reverb-1', 'line' => 'password is ********']],
                'truncated' => false,
                'error' => null,
            ]);

        $this->assertStringContainsString('process%3D%22reverb%22%7D+%7C%7E+%22%28%3Fi%29password%22&start=2026-10-29T18%3A00%3A00%2B00%3A00&end=2026-10-29T19%3A00%3A00%2B00%3A00', $kubectl->calls[0]['args'][8]);
    }

    public function test_an_unknown_process_or_a_bad_range_is_refused(): void
    {
        $environment = $this->makeEnvironment();
        $range = 'from=2026-10-29T18:00:00Z&to=2026-10-29T19:00:00Z';
        $user = User::factory()->create();
        $kubectl = FakeKubectl::install();

        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?process=deploy&{$range}")->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?process=worker&{$range}")->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?{$range}")->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?process=web")->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?process=web&from=2026-10-29T19:00:00Z&to=2026-10-29T18:00:00Z")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'The end of the range must be after its start.']);
        $this->actingAs($user)->getJson("/environments/{$environment->id}/logs/read?process=web&{$range}&search=".str_repeat('a', 201))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search' => 'Use 200 characters or fewer in the search.']);
        $this->assertSame([], $kubectl->calls);
    }

    public function test_a_log_store_that_does_not_answer_gives_a_plain_message(): void
    {
        FakeKubectl::install()->respond('exec', new KubectlResult(1, '', 'error: unable to upgrade connection: container not found ("query")'));
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=web&from=2026-10-29T18:00:00Z&to=2026-10-29T19:00:00Z")
            ->assertOk()
            ->assertExactJson(['lines' => [], 'truncated' => false, 'error' => 'The log store of the cluster does not answer. Try again in a minute.']);
    }

    public function test_guests_get_no_logs(): void
    {
        $environment = $this->makeEnvironment();

        $this->getJson("/environments/{$environment->id}/logs/read?process=web")->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature\Http;

use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlException;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnvironmentLogControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_offers_web_and_the_processes_of_the_environment(): void
    {
        $environment = $this->makeEnvironment();
        $environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false]);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}/logs")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('environments/logs')->where('processes', ['web', 'worker']));
    }

    public function test_the_json_endpoint_returns_masked_logs_from_the_action(): void
    {
        $kubectl = FakeKubectl::install();
        $kubectl->respond('logs', new KubectlResult(0, "[pod/web-1/web] password is hunter22\n"));
        $environment = $this->makeEnvironment();
        $environment->processes()->create(['name' => 'reverb', 'command' => 'php artisan reverb:start', 'instances' => 1, 'exclusive' => false]);
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=reverb")
            ->assertOk()
            ->assertExactJson(['log' => "[pod/web-1/web] password is ********\n", 'error' => null]);

        $this->assertStringEndsWith('opencloud.dev/process=reverb', $kubectl->calls[0]['args'][2]);
    }

    public function test_an_unknown_process_is_refused(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=deploy")->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=worker")->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read")->assertUnprocessable();
    }

    public function test_kubectl_failing_gives_a_plain_message(): void
    {
        FakeKubectl::install()->respond('logs', fn () => throw new KubectlException('kubectl is not installed'));
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=web")
            ->assertOk()
            ->assertExactJson(['log' => '', 'error' => 'The logs are not available yet. Deploy the environment first.']);
    }

    public function test_guests_get_no_logs(): void
    {
        $environment = $this->makeEnvironment();

        $this->getJson("/environments/{$environment->id}/logs/read?process=web")->assertUnauthorized();
    }
}

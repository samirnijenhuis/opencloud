<?php

namespace Tests\Feature\Http;

use App\Kube\FakeKubectl;
use App\Kube\KubectlException;
use App\Kube\KubectlResult;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnvironmentLogControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_page_offers_only_the_processes_that_are_enabled(): void
    {
        $environment = $this->makeEnvironment(attributes: ['worker_enabled' => true, 'scheduler_enabled' => false]);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}/logs")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('environments/logs')->where('processes', ['web', 'worker']));
    }

    public function test_the_json_endpoint_returns_masked_logs_from_the_action(): void
    {
        $kubectl = FakeKubectl::install();
        $kubectl->respond('logs', new KubectlResult(0, "[pod/web-1/web] password is hunter22\n"));
        $environment = $this->makeEnvironment();
        EnvVar::factory()->for($environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=worker")
            ->assertOk()
            ->assertExactJson(['log' => "[pod/web-1/web] password is ********\n", 'error' => null]);

        $this->assertStringContainsString('opencloud.dev/process=worker', $kubectl->calls[0]['args'][2]);
    }

    public function test_an_unknown_process_is_refused(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/logs/read?process=deploy")->assertUnprocessable()->assertJsonValidationErrors('process');
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

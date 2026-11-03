<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsEnvironments;
use Tests\Concerns\WithGitHubApp;
use Tests\TestCase;

class PreviewPullRequestControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase, WithGitHubApp;

    private Application $application;

    private Environment $production;

    private Environment $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->production = $this->makeEnvironment();
        $this->application = $this->production->application;
        $this->template = Environment::factory()->for($this->application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null, 'auto_deploy' => false]);
        $this->makeGitHubApp();
    }

    public function test_the_page_lists_each_open_pull_request_with_its_preview_or_the_reason_that_it_gets_none(): void
    {
        $this->application->update(['preview_labels' => ['preview']]);
        $preview = Environment::factory()->for($this->application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12, 'branch' => 'feature/12']);
        Deployment::factory()->for($preview)->create(['status' => DeploymentStatus::FAILED]);
        Deployment::factory()->for($preview)->create(['status' => DeploymentStatus::LIVE]);
        $this->fakePullRequests([
            self::apiPullRequest(12, ['preview']),
            self::apiPullRequest(13, ['Preview']),
            self::apiPullRequest(14, ['preview'], 'mallory/shop'),
            self::apiPullRequest(15, ['bug']),
        ]);

        $this->actingAs(User::factory()->create())->get("/environments/{$this->template->id}/pull-requests")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/pull-requests')
                ->where('repository', 'acme/shop')
                ->where('pull_requests', [
                    [...$this->row(12, ['preview']), 'preview' => ['id' => $preview->id, 'name' => 'pr-12', 'status' => 'live'], 'refusal' => null],
                    [...$this->row(13, ['Preview']), 'preview' => null, 'refusal' => null],
                    [...$this->row(14, ['preview']), 'preview' => null, 'refusal' => 'Opencloud does not preview a pull request from a fork.'],
                    [...$this->row(15, ['bug']), 'preview' => null, 'refusal' => 'It needs one of the labels: preview.'],
                ]));
    }

    public function test_with_previews_off_each_pull_request_says_to_turn_them_on(): void
    {
        $this->application->update(['previews_enabled' => false]);
        $this->fakePullRequests([self::apiPullRequest(12)]);

        $this->actingAs(User::factory()->create())->get("/environments/{$this->template->id}/pull-requests")
            ->assertInertia(fn (Assert $page) => $page->where('pull_requests.0.refusal', 'Turn on previews first.'));
    }

    public function test_without_the_github_app_on_the_repository_the_page_has_no_pull_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/acme/shop/installation' => Http::response(['message' => 'Not Found'], 404)]);

        $this->actingAs(User::factory()->create())->get("/environments/{$this->template->id}/pull-requests")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('repository', 'acme/shop')->where('pull_requests', null)->where('github_error', null));
    }

    /** @return array<string, array{Closure(): mixed, class-string<HttpClientException>}> */
    public static function outages(): array
    {
        return [
            'an error answer' => [fn () => Http::response(['message' => 'Bad gateway'], 502), RequestException::class],
            'no answer' => [fn () => Http::failedConnection(), ConnectionException::class],
        ];
    }

    /**
     * @param  Closure(): mixed  $answer
     * @param  class-string<HttpClientException>  $reported
     */
    #[DataProvider('outages')]
    public function test_when_github_does_not_answer_the_page_says_so_and_reports_the_error(Closure $answer, string $reported): void
    {
        Exceptions::fake();
        $this->fakeGitHub(more: ['api.github.com/repos/acme/shop/pulls?*' => $answer()]);

        $this->actingAs(User::factory()->create())->get("/environments/{$this->template->id}/pull-requests")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/pull-requests')
                ->where('pull_requests', null)
                ->where('github_error', 'GitHub did not answer. Try again in a minute.'));

        Exceptions::assertReported($reported);
    }

    public function test_a_developer_deploys_a_pull_request_from_the_page_and_a_refusal_comes_back_as_the_deploy_error(): void
    {
        Queue::fake();
        FakeKubectl::install();
        $this->fakeGitHub(more: [
            'api.github.com/repos/acme/shop/pulls/13' => Http::response(self::apiPullRequest(13)),
            'api.github.com/repos/acme/shop/pulls/14' => Http::response(self::apiPullRequest(14, [], 'mallory/shop')),
        ]);
        $page = "/environments/{$this->template->id}/pull-requests";
        $this->actingAs(User::factory()->create());

        $this->from($page)->post("/environments/{$this->template->id}/pull-requests/13/preview")->assertRedirect($page);
        $this->from($page)->post("/environments/{$this->template->id}/pull-requests/14/preview")
            ->assertRedirect($page)
            ->assertSessionHasErrors(['deploy' => 'Opencloud does not preview a pull request from a fork.']);

        $this->assertSame(['pr-13'], $this->application->environments()->where('kind', EnvironmentKind::PREVIEW)->pluck('name')->all());
        Queue::assertPushedOn('builds', RunDeployment::class);
    }

    public function test_only_the_preview_template_has_the_pull_requests_page_and_its_deploy(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->owner()->create());

        $this->get("/environments/{$this->production->id}/pull-requests")->assertForbidden();
        $this->post("/environments/{$this->production->id}/pull-requests/12/preview")->assertForbidden();

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    /** @param  list<array<string, mixed>>  $pullRequests */
    private function fakePullRequests(array $pullRequests): void
    {
        $this->fakeGitHub(more: ['api.github.com/repos/acme/shop/pulls?*' => Http::response($pullRequests)]);
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function row(int $number, array $labels): array
    {
        return [
            'number' => $number,
            'title' => "Change number {$number}",
            'author' => 'samir',
            'head_ref' => "feature/{$number}",
            'labels' => $labels,
            'url' => "https://github.com/acme/shop/pull/{$number}",
        ];
    }
}

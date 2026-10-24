<?php

namespace Tests\Feature\Previews;

use App\Enums\ClusterKind;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Jobs\RunDeployment;
use App\Kube\FakeKubectl;
use App\Kube\KubectlResult;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Deployment;
use App\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeProcesses;
use Tests\Feature\GitHub\WithGitHubApp;
use Tests\TestCase;

class PreviewCommentTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private Environment $preview;

    protected function setUp(): void
    {
        parent::setUp();

        FakeProcesses::install()->withLaravelRepository();
        FakeKubectl::install()->respond('exec deploy/web', new KubectlResult(0, 'HTTP/1.1 200 OK'));
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $application = Application::factory()->for($cluster)->create(['repository' => 'acme/shop']);
        $this->preview = Environment::factory()->for($application)->create([
            'name' => 'pr-12',
            'kind' => EnvironmentKind::Preview,
            'pr_number' => 12,
            'branch' => 'feature/login',
        ]);
        $this->preview->domains()->create(['hostname' => 'pr-12.preview.example.com']);
        $this->makeGitHubApp();
    }

    public function test_the_first_live_deployment_creates_the_comment_and_the_next_one_updates_it(): void
    {
        $this->fakeGitHub(more: [
            'api.github.com/repos/acme/shop/issues/12/comments' => Http::response(['id' => 555], 201),
            'api.github.com/repos/acme/shop/issues/comments/555' => Http::response(['id' => 555]),
        ]);

        RunDeployment::dispatchSync($this->deployment('abcdef1234567890abcdef1234567890abcdef12'));
        RunDeployment::dispatchSync($this->deployment('fedcba0987654321fedcba0987654321fedcba09'));

        $this->assertSame([
            ['POST', 'https://api.github.com/repos/acme/shop/issues/12/comments', "Opencloud deployed commit abcdef1 of this pull request.\n\nPreview: https://pr-12.preview.example.com"],
            ['PATCH', 'https://api.github.com/repos/acme/shop/issues/comments/555', "Opencloud deployed commit fedcba0 of this pull request.\n\nPreview: https://pr-12.preview.example.com"],
        ], $this->commentCalls());
        $this->assertSame(555, $this->preview->fresh()->pr_comment_id);
    }

    public function test_a_comment_that_someone_deleted_is_created_again(): void
    {
        $this->preview->update(['pr_comment_id' => 555]);
        $this->fakeGitHub(more: [
            'api.github.com/repos/acme/shop/issues/comments/555' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/acme/shop/issues/12/comments' => Http::response(['id' => 777], 201),
        ]);

        RunDeployment::dispatchSync($this->deployment('abcdef1234567890abcdef1234567890abcdef12'));

        $this->assertSame(['PATCH', 'POST'], array_column($this->commentCalls(), 0));
        $this->assertSame(777, $this->preview->fresh()->pr_comment_id);
    }

    public function test_a_refused_comment_goes_to_the_log_and_the_preview_stays_live(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);
        Log::spy();
        $deployment = $this->deployment('abcdef1234567890abcdef1234567890abcdef12');

        RunDeployment::dispatchSync($deployment);

        $this->assertSame(DeploymentStatus::Live, $deployment->fresh()->status);
        $this->assertNull($this->preview->fresh()->pr_comment_id);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'Opencloud could not post the preview URL on acme/shop#12.')->once();
    }

    public function test_a_permanent_environment_that_goes_live_posts_no_comment(): void
    {
        $this->fakeGitHub();
        $this->preview->update(['kind' => EnvironmentKind::Permanent, 'pr_number' => null]);

        RunDeployment::dispatchSync($this->deployment('abcdef1234567890abcdef1234567890abcdef12'));

        $this->assertSame([], $this->commentCalls());
    }

    private function deployment(string $sha): Deployment
    {
        return Deployment::factory()->for($this->preview)->create(['commit_sha' => $sha]);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function commentCalls(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/comments'))
            ->map(fn (array $pair) => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data()['body']])
            ->values()
            ->all();
    }
}

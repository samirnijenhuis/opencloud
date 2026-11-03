<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Previews\Actions\DeployPullRequest;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithGitHubApp;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DeployPullRequestTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private Application $application;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class, ProvisionDatastoreAttachment::class, CheckDomain::class, SyncCaddy::class]);
        FakeKubectl::install();
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/main\n";
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);
        $this->application = app(CreateApplication::class)->execute(User::factory()->owner()->create(), [
            'name' => 'Shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
        ]);
        $this->developer = User::factory()->create();
        $this->makeGitHubApp();
        AuditEvent::query()->delete();
    }

    public function test_a_pull_request_that_was_open_before_gets_its_preview_and_a_second_deploy_reuses_it(): void
    {
        $this->fakePullRequests(['api.github.com/repos/acme/shop/pulls/12' => Http::response(self::apiPullRequest(12))]);
        $deploy = app(DeployPullRequest::class);

        $deploy->execute($this->application, $this->developer, 12);
        $deploy->execute($this->application, $this->developer, 12);

        $preview = $this->application->environments()->where('kind', EnvironmentKind::PREVIEW)->sole();
        $this->assertSame(['pr-12', 12, 'feature/12'], [$preview->name, $preview->pr_number, $preview->branch]);
        $this->assertSame(
            [[self::HEAD_SHA, $this->developer->id], [self::HEAD_SHA, $this->developer->id]],
            $preview->deployments()->orderBy('id')->get(['commit_sha', 'triggered_by'])->map(fn (Deployment $deployment) => [$deployment->commit_sha, $deployment->triggered_by])->all(),
        );
        Queue::assertPushedOn('builds', RunDeployment::class);
        $this->assertSame(
            array_fill(0, 2, [$this->developer->id, $this->application->id, ['pr_number' => 12, 'commit_sha' => self::HEAD_SHA]]),
            AuditEvent::query()->where('action', 'preview.deploy_requested')->orderBy('id')->get()
                ->map(fn (AuditEvent $event) => [$event->user_id, $event->subject_id, $event->context])->all(),
        );
    }

    /** @return array<string, array{array<string, mixed>|null, array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'closed' => [self::apiPullRequest(12, state: 'closed'), [], 'Pull request #12 is closed. Only an open pull request gets a preview.'],
            'from a fork' => [self::apiPullRequest(12, headRepository: 'mallory/shop'), [], 'Opencloud does not preview a pull request from a fork.'],
            'previews off' => [self::apiPullRequest(12), ['previews_enabled' => false], 'Turn on previews first.'],
            'no filter label' => [self::apiPullRequest(12, ['bug']), ['preview_labels' => ['preview', 'deploy-me']], 'It needs one of the labels: preview, deploy-me.'],
            'unknown number' => [null, [], 'Opencloud cannot read pull request #12. Check that the GitHub App is installed on the repository.'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $pullRequest  null when GitHub does not know the number
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('refusals')]
    public function test_a_pull_request_that_cannot_get_a_preview_is_refused_with_the_reason(?array $pullRequest, array $settings, string $message): void
    {
        $this->application->update($settings);
        $this->fakePullRequests(['api.github.com/repos/acme/shop/pulls/12' => $pullRequest === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($pullRequest)]);

        $this->assertRefused($message, 12);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNotPushed(RunDeployment::class);
    }

    public function test_at_the_limit_a_new_preview_is_refused_without_a_comment_but_a_running_preview_deploys_again(): void
    {
        $this->application->update(['max_previews' => 1]);
        $this->fakePullRequests([
            'api.github.com/repos/acme/shop/pulls/12' => Http::response(self::apiPullRequest(12)),
            'api.github.com/repos/acme/shop/pulls/13' => Http::response(self::apiPullRequest(13)),
        ]);
        $deploy = app(DeployPullRequest::class);
        $deploy->execute($this->application, $this->developer, 12);

        $this->assertRefused('Shop already runs 1 previews, which is the limit. Close a pull request that has a preview, or raise the limit.', 13);
        $deploy->execute($this->application, $this->developer, 12);

        $this->assertSame(['pr-12'], Environment::query()->where('kind', EnvironmentKind::PREVIEW)->pluck('name')->all());
        $this->assertSame(2, $this->application->environments()->where('name', 'pr-12')->sole()->deployments()->count());
        $this->assertSame([], Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/comments'))->all());
        $this->assertSame(0, AuditEvent::query()->where('action', 'preview.limit_reached')->count());
    }

    /** @param  array<string, mixed>  $responses */
    private function fakePullRequests(array $responses): void
    {
        $this->fakeGitHub(more: [...$responses, 'api.github.com/repos/acme/shop/issues/*' => Http::response(['id' => 9001], 201)]);
    }

    private function assertRefused(string $message, int $number): void
    {
        try {
            app(DeployPullRequest::class)->execute($this->application->fresh(), $this->developer, $number);
            $this->fail('The deploy was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['deploy' => [$message]], $e->errors());
        }
    }
}

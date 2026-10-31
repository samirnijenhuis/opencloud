<?php

namespace Tests\Http\Controllers;

use App\Domains\Environments\Policies\EnvironmentPolicy;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsEnvironments;
use Tests\Concerns\WithGitHubApp;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class RepositoryAccessControllerTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;
    use WithGitHubApp;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    public function test_the_check_reads_the_normalized_repository_and_returns_the_access_json(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');
        Http::preventStrayRequests();
        $git = FakeProcesses::install();
        $git->symref = "ref: refs/heads/trunk\tHEAD\n";

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/applications/create/repository-access?repository=git@github.com:acme/shop.git')
            ->assertOk()
            ->assertExactJson([
                'repository' => 'https://github.com/acme/shop.git',
                'github_repository' => 'acme/shop',
                'github_url' => 'https://github.com/acme/shop',
                'read' => 'public',
                'read_works' => true,
                'summary' => 'Public repository. Opencloud can read it.',
                'default_branch' => 'trunk',
                'app' => 'no_app',
                'app_slug' => null,
                'app_owner' => null,
                'install_url' => null,
                'checked_at' => '2026-10-31T12:00:00+00:00',
            ]);

        $this->assertSame([['git', 'ls-remote', '--symref', 'https://github.com/acme/shop.git', 'HEAD']], $git->commands());
    }

    public function test_the_check_needs_the_right_to_create_an_application_and_a_valid_repository(): void
    {
        Http::preventStrayRequests();
        $git = FakeProcesses::install();

        $this->actingAs(User::factory()->create())
            ->getJson('/applications/create/repository-access?repository=acme/shop')
            ->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/applications/create/repository-access?repository=not%20a%20repository')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository' => 'Use owner/repo, git@github.com:owner/repo.git, or a full https address.']);

        $this->assertSame([], $git->commands());
    }

    public function test_the_check_allows_thirty_requests_a_minute(): void
    {
        Http::preventStrayRequests();
        FakeProcesses::install();
        $this->actingAs(User::factory()->admin()->create());

        foreach (range(1, 30) as $request) {
            $this->getJson('/applications/create/repository-access?repository=acme/shop')->assertOk();
        }

        $this->getJson('/applications/create/repository-access?repository=acme/shop')->assertTooManyRequests();
    }

    public function test_the_environment_route_adds_the_branch_the_deliveries_the_public_key_and_the_rights(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $git = FakeProcesses::install();
        $git->symref = "ref: refs/heads/main\tHEAD\n".self::SHA."\trefs/heads/main\n";
        $environment = $this->makeEnvironment();
        $environment->application->update(['deploy_public_key' => FakeProcesses::PUBLIC_KEY]);
        $json = [
            'repository' => 'https://github.com/acme/shop.git',
            'github_repository' => 'acme/shop',
            'github_url' => 'https://github.com/acme/shop',
            'read' => 'github_app',
            'read_works' => true,
            'summary' => 'The GitHub App reads this repository.',
            'default_branch' => 'main',
            'app' => 'installed',
            'app_slug' => 'opencloud-dash',
            'app_owner' => 'acme',
            'install_url' => 'https://github.com/apps/opencloud-dash/installations/new',
            'checked_at' => '2026-10-31T12:00:00+00:00',
            'branch' => 'main',
            'push' => 'github_app',
            'previews' => 'on',
            'deploy_public_key' => FakeProcesses::PUBLIC_KEY,
        ];

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/repository-access")
            ->assertOk()
            ->assertExactJson([...$json, 'can_update' => false, 'can_manage_credentials' => false]);
        $this->actingAs(User::factory()->admin()->create())->getJson("/environments/{$environment->id}/repository-access")
            ->assertOk()
            ->assertExactJson([...$json, 'can_update' => true, 'can_manage_credentials' => false]);
        $this->actingAs(User::factory()->owner()->create())->getJson("/environments/{$environment->id}/repository-access")
            ->assertOk()
            ->assertExactJson([...$json, 'can_update' => true, 'can_manage_credentials' => true]);

        $this->assertSame([['git', 'ls-remote', '--symref', 'https://x-access-token:'.self::TOKEN.'@github.com/acme/shop.git', 'HEAD', 'refs/heads/main']], $git->commands());
    }

    public function test_the_environment_route_refuses_a_user_whom_the_view_policy_refuses_and_reads_nothing(): void
    {
        Http::preventStrayRequests();
        $git = FakeProcesses::install();
        $environment = $this->makeEnvironment();
        $this->mock(EnvironmentPolicy::class)->shouldReceive('view')->andReturnFalse();

        $this->actingAs(User::factory()->create())->getJson("/environments/{$environment->id}/repository-access")->assertForbidden();
        $this->assertSame([], $git->commands());
    }
}

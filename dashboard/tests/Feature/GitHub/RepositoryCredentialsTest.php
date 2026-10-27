<?php

namespace Tests\Feature\GitHub;

use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Git;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RepositoryCredentialsTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /** @var list<list<string>> */
    private array $commands = [];

    public function test_ls_remote_of_an_installed_repository_uses_the_installation_token(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $this->fakeGit();

        $this->assertSame(self::SHA, Git::head('https://github.com/acme/shop.git', 'main'));
        $this->assertSame([['git', 'ls-remote', 'https://x-access-token:'.self::TOKEN.'@github.com/acme/shop.git', 'refs/heads/main']], $this->commands);
    }

    public function test_a_public_repository_works_without_the_app_and_when_github_refuses_the_app(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);
        Log::spy();
        $this->fakeGit();

        Git::head('https://github.com/laravel/laravel.git', '13.x');
        $this->assertCount(0, Http::recorded());

        $this->makeGitHubApp();
        Git::head('https://github.com/laravel/laravel.git', '13.x');

        $this->assertSame(array_fill(0, 2, ['git', 'ls-remote', 'https://github.com/laravel/laravel.git', 'refs/heads/13.x']), $this->commands);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'Opencloud could not get a GitHub token for laravel/laravel. It uses the URL without a token.');
    }

    public function test_the_token_never_appears_in_the_deployment_log_when_the_clone_fails(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        FakeKubectl::install();
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $tokenUrl = 'https://x-access-token:'.self::TOKEN.'@github.com/acme/shop.git';
        $this->fakeGit(fetch: Process::result(errorOutput: "fatal: unable to access '{$tokenUrl}/': The requested URL returned error: 403", exitCode: 128));
        $application = Application::factory()->create(['repository' => 'acme/shop']);
        $deployment = Deployment::factory()->for(Environment::factory()->for($application))->create(['commit_sha' => self::SHA]);

        RunDeployment::dispatchSync($deployment);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame(['git', 'fetch', '--quiet', '--depth', '1', $tokenUrl, self::SHA], $this->commands[1]);
        $this->assertStringNotContainsString(self::TOKEN, $deployment->log);
        $this->assertStringContainsString(
            "2026-10-23T12:00:00Z fatal: unable to access 'https://x-access-token:********@github.com/acme/shop.git/': The requested URL returned error: 403\n",
            $deployment->log,
        );
    }

    private function fakeGit(mixed $fetch = null): void
    {
        Process::fake(['*' => function (PendingProcess $process) use ($fetch) {
            $this->commands[] = $process->command;

            return match ($process->command[1]) {
                'ls-remote' => Process::result(self::SHA."\trefs/heads/main\n"),
                'fetch' => $fetch ?? Process::result(),
                default => Process::result(),
            };
        }]);
    }
}

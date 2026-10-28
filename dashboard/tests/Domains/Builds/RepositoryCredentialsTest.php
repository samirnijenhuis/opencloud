<?php

namespace Tests\Domains\Builds;

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
use Tests\Concerns\WithGitHubApp;
use Tests\TestCase;

class RepositoryCredentialsTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmU=\n-----END OPENSSH PRIVATE KEY-----\n";

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<array<string, string>> */
    private array $envs = [];

    /** @var list<array{0: string, 1: string, 2: string}> the path, the mode, and the contents of the key file during each command */
    private array $keyFiles = [];

    public function test_ls_remote_of_an_installed_repository_uses_the_installation_token(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $this->fakeGit();

        $this->assertSame(self::SHA, Git::head(new Application(['repository' => 'https://github.com/acme/shop.git']), 'main'));
        $this->assertSame([['git', 'ls-remote', 'https://x-access-token:'.self::TOKEN.'@github.com/acme/shop.git', 'refs/heads/main']], $this->commands);
    }

    public function test_an_application_with_a_deploy_key_reads_github_over_ssh_with_the_pinned_host_keys_and_not_with_the_app_token(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $this->fakeGit();

        $this->assertSame(self::SHA, Git::head(new Application(['repository' => 'https://github.com/acme/shop.git', 'deploy_private_key' => self::KEY]), 'main'));

        [$keyFile, $mode, $contents] = $this->keyFiles[0];
        $this->assertSame([['git', 'ls-remote', 'git@github.com:acme/shop.git', 'refs/heads/main']], $this->commands);
        $this->assertSame([
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => "ssh -i '{$keyFile}' -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='".base_path('resources/ssh/github_known_hosts')."'",
        ], $this->envs[0]);
        $this->assertSame(['0600', self::KEY], [$mode, $contents]);
        $this->assertFileDoesNotExist($keyFile);
        $this->assertCount(0, Http::recorded());
    }

    public function test_a_refused_deploy_key_fails_the_deployment_with_the_git_error_and_the_step_that_fixes_it_and_leaves_no_key_file(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        FakeKubectl::install();
        $this->fakeGit(fetch: Process::result(errorOutput: "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n", exitCode: 128));
        $application = Application::factory()->create(['repository' => 'acme/shop', 'deploy_private_key' => self::KEY]);
        $deployment = Deployment::factory()->for(Environment::factory()->for($application))->create(['commit_sha' => self::SHA]);

        RunDeployment::dispatchSync($deployment);

        $deployment->refresh();
        $keyFiles = array_values(array_unique(array_column($this->keyFiles, 0)));
        $this->assertSame(DeploymentStatus::FAILED, $deployment->status);
        $this->assertSame(['git', 'fetch', '--quiet', '--depth', '1', 'git@github.com:acme/shop.git', self::SHA], $this->commands[1]);
        $this->assertStringContainsString("2026-10-23T12:00:00Z git@github.com: Permission denied (publickey).\n", $deployment->log);
        $this->assertStringEndsWith("2026-10-23T12:00:00Z GitHub refused the deploy key. Add the public key to the repository under Settings, Deploy keys.\n", $deployment->log);
        $this->assertCount(1, $keyFiles);
        $this->assertFileDoesNotExist($keyFiles[0]);
    }

    public function test_a_public_repository_works_without_the_app_and_when_github_refuses_the_app(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);
        Log::spy();
        $this->fakeGit();

        $application = new Application(['repository' => 'https://github.com/laravel/laravel.git']);
        Git::head($application, '13.x');
        $this->assertCount(0, Http::recorded());

        $this->makeGitHubApp();
        Git::head($application, '13.x');

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
        $this->assertSame(DeploymentStatus::FAILED, $deployment->status);
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
            $this->envs[] = $process->environment;

            if (preg_match("#^ssh -i '([^']+)'#", $process->environment['GIT_SSH_COMMAND'] ?? '', $match) === 1) {
                $this->keyFiles[] = [$match[1], substr(sprintf('%o', fileperms($match[1])), -4), file_get_contents($match[1])];
            }

            return match ($process->command[1]) {
                'ls-remote' => Process::result(self::SHA."\trefs/heads/main\n"),
                'fetch' => $fetch ?? Process::result(),
                default => Process::result(),
            };
        }]);
    }
}

<?php

namespace Tests\Domains\Applications;

use App\Domains\Applications\Enums\RepositoryRead;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\RepositoryProbe;
use App\Domains\GitHub\Enums\AppReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithGitHubApp;
use Tests\TestCase;

class RepositoryProbeTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmU=\n-----END OPENSSH PRIVATE KEY-----\n";

    private const MAIN = "ref: refs/heads/main\tHEAD\n".self::SHA."\tHEAD\n";

    /** @var list<list<string>> */
    private array $commands = [];

    public function test_an_installed_app_reads_the_repository_with_one_ls_remote_through_the_token(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $this->fakeGit(self::MAIN.self::SHA."\trefs/heads/main\n");

        $access = app(RepositoryProbe::class)->access(new Application(['repository' => 'https://github.com/acme/shop.git']), 'main');

        $this->assertSame([RepositoryRead::GITHUB_APP, 'main', AppReach::INSTALLED], [$access->read, $access->defaultBranch, $access->app]);
        $this->assertSame([['git', 'ls-remote', '--symref', 'https://x-access-token:'.self::TOKEN.'@github.com/acme/shop.git', 'HEAD', 'refs/heads/main']], $this->commands);
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: string, 3: string, 4: RepositoryRead, 5: ?string, 6: list<string>}> */
    public static function reads(): array
    {
        $plain = ['git', 'ls-remote', '--symref', 'https://github.com/acme/shop.git', 'HEAD'];
        $ssh = ['git', 'ls-remote', '--symref', 'git@github.com:acme/shop.git', 'HEAD', 'refs/heads/main'];

        return [
            'a deploy key' => [self::KEY, 'main', self::MAIN.self::SHA."\trefs/heads/main\n", '', RepositoryRead::DEPLOY_KEY, 'main', $ssh],
            'a public repository' => [null, null, "ref: refs/heads/trunk\tHEAD\n".self::SHA."\tHEAD\n", '', RepositoryRead::PUBLIC, 'trunk', $plain],
            'a missing branch' => [null, 'develop', self::MAIN, '', RepositoryRead::BRANCH_MISSING, 'main', [...$plain, 'refs/heads/develop']],
            'a refused key' => [self::KEY, 'main', '', "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n", RepositoryRead::KEY_REFUSED, null, $ssh],
            'a private repository' => [null, 'main', '', "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", RepositoryRead::UNREADABLE, null, [...$plain, 'refs/heads/main']],
        ];
    }

    /** @param  list<string>  $command */
    #[DataProvider('reads')]
    public function test_the_git_answer_gives_the_read_state(?string $key, ?string $branch, string $output, string $error, RepositoryRead $read, ?string $defaultBranch, array $command): void
    {
        Http::preventStrayRequests();
        $this->fakeGit($output, $error);

        $access = app(RepositoryProbe::class)->access(new Application(['repository' => 'https://github.com/acme/shop.git', 'deploy_private_key' => $key]), $branch);

        $this->assertSame([$read, $defaultBranch, AppReach::NO_APP], [$access->read, $access->defaultBranch, $access->app]);
        $this->assertSame([$command], $this->commands);
    }

    public function test_a_repository_on_another_host_asks_github_nothing(): void
    {
        Http::preventStrayRequests();
        $this->makeGitHubApp();
        $this->fakeGit(self::MAIN);

        $access = app(RepositoryProbe::class)->access(new Application(['repository' => 'https://gitlab.com/acme/shop.git']), null);

        $this->assertSame([RepositoryRead::PUBLIC, null, AppReach::NOT_GITHUB], [$access->read, $access->githubRepository, $access->app]);
        $this->assertCount(0, Http::recorded());
    }

    public function test_a_failed_github_call_gives_an_unknown_reach_and_a_warning_but_keeps_the_git_answer(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/acme/shop/installation' => Http::response(['message' => 'Server Error'], 500)]);
        Log::spy();
        $this->makeGitHubApp();
        $this->fakeGit(self::MAIN);

        $access = app(RepositoryProbe::class)->access(new Application(['repository' => 'https://github.com/acme/shop.git']), null);

        $this->assertSame([RepositoryRead::PUBLIC, AppReach::UNKNOWN], [$access->read, $access->app]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'Opencloud could not ask GitHub whether the app reaches acme/shop.')->once();
    }

    public function test_the_answer_stays_ten_seconds_but_a_new_deploy_key_or_branch_gets_a_fresh_check(): void
    {
        Http::preventStrayRequests();
        $this->fakeGit(self::MAIN.self::SHA."\trefs/heads/main\n".self::SHA."\trefs/heads/develop\n");
        $probe = app(RepositoryProbe::class);
        $application = new Application(['repository' => 'https://github.com/acme/shop.git']);

        $this->assertSame(RepositoryRead::PUBLIC, $probe->access($application, 'main')->read);
        $this->assertSame(RepositoryRead::PUBLIC, $probe->access($application, 'main')->read);
        $this->assertCount(1, $this->commands);

        $application->deploy_private_key = self::KEY;
        $this->assertSame(RepositoryRead::DEPLOY_KEY, $probe->access($application, 'main')->read);
        $probe->access($application, 'develop');
        $this->assertCount(3, $this->commands);

        $this->travel(11)->seconds();
        $probe->access($application, 'main');
        $this->assertCount(4, $this->commands);
    }

    private function fakeGit(string $output, string $error = ''): void
    {
        Process::fake(['*' => function (PendingProcess $process) use ($output, $error) {
            $this->commands[] = $process->command;

            return Process::result($output, $error, $error === '' ? 0 : 128);
        }]);
    }
}

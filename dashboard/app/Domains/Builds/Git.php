<?php

namespace App\Domains\Builds;

use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Enums\ReadPath;
use App\Domains\Builds\Exceptions\BranchNotFound;
use App\Domains\Builds\Exceptions\DeployKeyRefused;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\RepositoryName;
use App\Support\SecretMasker;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class Git
{
    /** A private repository without credentials must fail, not wait for a password. */
    private const ENV = ['GIT_TERMINAL_PROMPT' => '0'];

    /**
     * @throws BranchNotFound when the repository answers but the branch does not exist
     * @throws RuntimeException when git cannot read the repository
     */
    public static function head(Application $application, string $branch): string
    {
        $output = self::withRemote($application, fn (string $remote, array $env, SecretMasker $masker) => self::run(
            Process::env($env)->timeout(60), ['git', 'ls-remote', $remote, "refs/heads/{$branch}"], $masker,
        ));

        if (preg_match('/^([0-9a-f]{40})\s/m', $output, $match) !== 1) {
            throw new BranchNotFound("The branch {$branch} does not exist in {$application->cloneUrl()}.");
        }

        return $match[1];
    }

    /** @throws RuntimeException when the repository has no default branch or cannot be reached */
    public static function defaultBranch(Application $application): string
    {
        return self::read($application, null)['default_branch']
            ?? throw new RuntimeException("The repository {$application->cloneUrl()} has no default branch.");
    }

    /**
     * Reads the repository the way a build does, with one ls-remote: the default branch and, with $branch, whether that branch exists.
     *
     * @return array{path: ReadPath, default_branch: ?string, branch_exists: bool} the default branch is null for an empty repository
     *
     * @throws DeployKeyRefused when GitHub refuses the deploy key
     * @throws RuntimeException when git cannot read the repository
     */
    public static function read(Application $application, ?string $branch): array
    {
        $refs = $branch === null ? ['HEAD'] : ['HEAD', "refs/heads/{$branch}"];
        [$path, $output] = self::withRemote($application, fn (string $remote, array $env, SecretMasker $masker, ReadPath $path) => [$path, self::run(
            Process::env($env)->timeout(60), ['git', 'ls-remote', '--symref', $remote, ...$refs], $masker,
        )]);

        return [
            'path' => $path,
            'default_branch' => preg_match('#^ref: refs/heads/(\S+)\s+HEAD$#m', $output, $match) === 1 ? $match[1] : null,
            'branch_exists' => $branch === null || preg_match('#^[0-9a-f]{40}\s+refs/heads/'.preg_quote($branch, '#').'$#m', $output) === 1,
        ];
    }

    /** Fetches exactly one commit into an empty folder. The fetch names the URL directly, so .git never stores a token. */
    public static function checkout(Application $application, string $sha, string $dir): void
    {
        self::withRemote($application, function (string $remote, array $env, SecretMasker $masker) use ($sha, $dir) {
            $commands = [
                ['git', 'init', '--quiet'],
                ['git', 'fetch', '--quiet', '--depth', '1', $remote, $sha],
                ['git', 'checkout', '--quiet', 'FETCH_HEAD'],
            ];

            foreach ($commands as $command) {
                self::run(Process::path($dir)->env($env)->timeout(600), $command, $masker);
            }
        });
    }

    /**
     * The one place that picks the remote. An application with a deploy key reaches github.com over SSH with that key,
     * and the key file lives only during the call. Else a github.com repository with the GitHub App installed gets
     * an installation token in its URL. Without the app, or when GitHub refuses it, the plain URL still works for a public repository.
     *
     * @template T
     *
     * @param  Closure(string, array<string, string>, SecretMasker, ReadPath): T  $git  gets the remote, the env, the masker of its errors, and the read path
     * @return T
     */
    private static function withRemote(Application $application, Closure $git): mixed
    {
        $repository = RepositoryName::parse($application->repository);

        if ($repository !== null && $application->deploy_private_key !== null) {
            // tempnam() creates the file with mode 0600, which ssh requires of a private key file.
            $keyFile = tempnam(sys_get_temp_dir(), 'opencloud-deploy-key-');

            try {
                file_put_contents($keyFile, $application->deploy_private_key);
                $ssh = 'ssh -i '.escapeshellarg($keyFile).' -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='.escapeshellarg(resource_path('ssh/github_known_hosts'));

                return $git("git@github.com:{$repository}.git", [...self::ENV, 'GIT_SSH_COMMAND' => $ssh], new SecretMasker([]), ReadPath::DEPLOY_KEY);
            } finally {
                unlink($keyFile);
            }
        }

        try {
            $token = $repository !== null ? app(GitHubClient::class)->installationToken($repository) : null;
        } catch (Throwable $e) {
            Log::warning("Opencloud could not get a GitHub token for {$repository}. It uses the URL without a token.", ['error' => $e->getMessage()]);
            $token = null;
        }

        if ($token === null) {
            return $git($application->cloneUrl(), self::ENV, new SecretMasker([]), ReadPath::PLAIN_URL);
        }

        return $git("https://x-access-token:{$token}@github.com/{$repository}.git", self::ENV, new SecretMasker([$token]), ReadPath::GITHUB_APP);
    }

    /**
     * The exception of a failed process quotes the command line, which holds the token. So the message is masked,
     * and the original exception is not chained. Only ssh prints "Permission denied (publickey)", so it means a refused deploy key.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException when the command fails
     */
    private static function run(PendingProcess $process, array $command, SecretMasker $masker): string
    {
        try {
            return $process->run($command)->throw()->output();
        } catch (RuntimeException $e) {
            $message = $masker->mask($e->getMessage());

            if (str_contains($message, 'Permission denied (publickey)')) {
                throw new DeployKeyRefused($message);
            }

            throw new RuntimeException($message, (int) $e->getCode());
        }
    }
}

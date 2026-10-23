<?php

namespace App\Build;

use App\GitHub\GitHubClient;
use App\GitHub\RepositoryName;
use App\Support\SecretMasker;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class Git
{
    /** A private repository without credentials must fail, not wait for a password. */
    private const ENV = ['GIT_TERMINAL_PROMPT' => '0'];

    /** @throws RuntimeException when the branch does not exist */
    public static function head(string $url, string $branch): string
    {
        [$remote, $masker] = self::remote($url);
        $output = self::run(Process::env(self::ENV)->timeout(60), ['git', 'ls-remote', $remote, "refs/heads/{$branch}"], $masker);

        if (preg_match('/^([0-9a-f]{40})\s/m', $output, $match) !== 1) {
            throw new RuntimeException("The branch {$branch} does not exist in {$url}.");
        }

        return $match[1];
    }

    /** @throws RuntimeException when the repository has no default branch or cannot be reached */
    public static function defaultBranch(string $url): string
    {
        [$remote, $masker] = self::remote($url);
        $output = self::run(Process::env(self::ENV)->timeout(60), ['git', 'ls-remote', '--symref', $remote, 'HEAD'], $masker);

        if (preg_match('#^ref: refs/heads/(\S+)\s+HEAD$#m', $output, $match) !== 1) {
            throw new RuntimeException("The repository {$url} has no default branch.");
        }

        return $match[1];
    }

    /** Fetches exactly one commit into an empty folder. The fetch names the URL directly, so .git never stores a token. */
    public static function checkout(string $url, string $sha, string $dir): void
    {
        [$remote, $masker] = self::remote($url);
        $commands = [
            ['git', 'init', '--quiet'],
            ['git', 'fetch', '--quiet', '--depth', '1', $remote, $sha],
            ['git', 'checkout', '--quiet', 'FETCH_HEAD'],
        ];

        foreach ($commands as $command) {
            self::run(Process::path($dir)->env(self::ENV)->timeout(600), $command, $masker);
        }
    }

    /**
     * A github.com repository with the GitHub App installed gets an installation token in its URL.
     * Without the app, or when GitHub refuses it, the plain URL still works for a public repository.
     *
     * @return array{0: string, 1: SecretMasker}
     */
    private static function remote(string $url): array
    {
        $repository = RepositoryName::parse($url);

        try {
            $token = $repository !== null ? app(GitHubClient::class)->installationToken($repository) : null;
        } catch (Throwable $e) {
            Log::warning("Opencloud could not get a GitHub token for {$repository}. It uses the URL without a token.", ['error' => $e->getMessage()]);
            $token = null;
        }

        if ($token === null) {
            return [$url, new SecretMasker([])];
        }

        return ["https://x-access-token:{$token}@github.com/{$repository}.git", new SecretMasker([$token])];
    }

    /**
     * The exception of a failed process quotes the command line, which holds the token. So the message is masked,
     * and the original exception is not chained.
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
            throw new RuntimeException($masker->mask($e->getMessage()), (int) $e->getCode());
        }
    }
}

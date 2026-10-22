<?php

namespace App\Build;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class Git
{
    /** A private repository without credentials must fail, not wait for a password. */
    private const ENV = ['GIT_TERMINAL_PROMPT' => '0'];

    /** @throws RuntimeException when the branch does not exist */
    public static function head(string $url, string $branch): string
    {
        $output = Process::env(self::ENV)->timeout(60)->run(['git', 'ls-remote', $url, "refs/heads/{$branch}"])->throw()->output();

        if (preg_match('/^([0-9a-f]{40})\s/m', $output, $match) !== 1) {
            throw new RuntimeException("The branch {$branch} does not exist in {$url}.");
        }

        return $match[1];
    }

    /** Fetches exactly one commit into an empty folder. */
    public static function checkout(string $url, string $sha, string $dir): void
    {
        $commands = [
            ['git', 'init', '--quiet'],
            ['git', 'remote', 'add', 'origin', $url],
            ['git', 'fetch', '--quiet', '--depth', '1', 'origin', $sha],
            ['git', 'checkout', '--quiet', 'FETCH_HEAD'],
        ];

        foreach ($commands as $command) {
            Process::path($dir)->env(self::ENV)->timeout(600)->run($command)->throw();
        }
    }
}

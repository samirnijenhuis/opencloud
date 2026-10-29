<?php

namespace Tests\Fakes;

use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Stands in for git, buildctl, crane, and ssh-keygen. A fake checkout writes $repository and $links into the build folder.
 * A fake ssh-keygen writes PRIVATE_KEY and PUBLIC_KEY with the comment of the call. A fake ssh-keygen -y reads
 * PUBLIC_KEY with the comment OWN_COMMENT from PRIVATE_KEY, and it refuses each other key as ssh-keygen does.
 */
final class FakeProcesses
{
    public const PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake-private-key\n-----END OPENSSH PRIVATE KEY-----\n";

    public const PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicKey';

    public const OWN_COMMENT = 'me@example.com';

    /** @var list<array{command: list<string>, path: ?string, env: array<string, string>}> */
    public array $ran = [];

    /** @var array<string, string> path => contents */
    public array $repository = [];

    /** @var array<string, string> path => target of a symbolic link that the checkout makes */
    public array $links = [];

    /** @var list<string> */
    public array $buildOutput = [];

    public int $buildExitCode = 0;

    public string $lsRemote = '';

    public string $symref = "ref: refs/heads/main\tHEAD\n";

    /** When set, each git ls-remote fails with this error output. */
    public ?string $lsRemoteError = null;

    /** @var list<string> the Dockerfile text that each build saw */
    public array $dockerfiles = [];

    /** @var list<string|null> the "<dockerfile>.dockerignore" that each build saw, or null */
    public array $dockerignores = [];

    /** @var list<array<string, string>> the build secrets that each build read from its files, by id */
    public array $secrets = [];

    /** @var list<string> the config.json that crane found in DOCKER_CONFIG for each copy */
    public array $craneConfigs = [];

    public int $craneExitCode = 0;

    public static function install(): self
    {
        $fake = new self;
        Process::fake(['*' => fn (PendingProcess $process) => $fake->handle($process)]);

        return $fake;
    }

    /** @return list<list<string>> */
    public function commands(): array
    {
        return array_column($this->ran, 'command');
    }

    public function withLaravelRepository(): self
    {
        $this->repository = [
            'composer.json' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']]),
            'package.json' => json_encode(['scripts' => ['build' => 'vite build']]),
        ];

        return $this;
    }

    private function handle(PendingProcess $process): mixed
    {
        $command = $process->command;
        $this->ran[] = ['command' => $command, 'path' => $process->path, 'env' => $process->environment];

        if ($command[0] === 'git' && $command[1] === 'checkout') {
            foreach ($this->repository as $path => $contents) {
                is_dir(dirname("{$process->path}/{$path}")) || mkdir(dirname("{$process->path}/{$path}"), 0777, true);
                file_put_contents("{$process->path}/{$path}", $contents);
            }

            foreach ($this->links as $path => $target) {
                is_dir(dirname("{$process->path}/{$path}")) || mkdir(dirname("{$process->path}/{$path}"), 0777, true);
                symlink($target, "{$process->path}/{$path}");
            }
        }

        if ($command[0] === 'git' && $command[1] === 'ls-remote' && $this->lsRemoteError !== null) {
            return Process::result(errorOutput: $this->lsRemoteError, exitCode: 128);
        }

        if ($command[0] === 'git' && $command[1] === 'ls-remote' && $command[2] === '--symref') {
            return Process::result($this->symref);
        }

        if ($command[0] === 'git' && $command[1] === 'ls-remote') {
            return Process::result($this->lsRemote);
        }

        if ($command[0] === 'buildctl') {
            $option = fn (string $name) => substr(collect($command)->first(fn (string $arg) => str_starts_with($arg, "{$name}=")), strlen($name) + 1);
            $dockerfile = $option('dockerfile').'/'.$option('filename');
            $this->dockerfiles[] = file_get_contents($dockerfile);
            $this->dockerignores[] = is_file("{$dockerfile}.dockerignore") ? file_get_contents("{$dockerfile}.dockerignore") : null;
            $this->secrets[] = collect($command)
                ->filter(fn (string $arg, int $i) => $i > 0 && $command[$i - 1] === '--secret')
                ->mapWithKeys(function (string $arg) {
                    parse_str(str_replace(',', '&', $arg), $secret);

                    return [$secret['id'] => file_get_contents($secret['src'])];
                })
                ->all();

            return (new FakeProcessDescription)->output($this->buildOutput)->exitCode($this->buildExitCode);
        }

        if ($command[0] === 'ssh-keygen' && in_array('-y', $command, true)) {
            $path = $command[array_search('-f', $command, true) + 1];

            return file_get_contents($path) === self::PRIVATE_KEY
                ? Process::result(self::PUBLIC_KEY.' '.self::OWN_COMMENT."\n")
                : Process::result(errorOutput: "Load key \"{$path}\": invalid format\n", exitCode: 255);
        }

        if ($command[0] === 'ssh-keygen') {
            $path = $command[array_search('-f', $command, true) + 1];
            file_put_contents($path, self::PRIVATE_KEY);
            file_put_contents("{$path}.pub", self::PUBLIC_KEY.' '.$command[array_search('-C', $command, true) + 1]."\n");
        }

        if ($command[0] === 'crane') {
            $this->craneConfigs[] = file_get_contents($process->environment['DOCKER_CONFIG'].'/config.json');

            return Process::result(errorOutput: $this->craneExitCode === 0 ? '' : 'UNAUTHORIZED: authentication required', exitCode: $this->craneExitCode);
        }

        return Process::result();
    }
}

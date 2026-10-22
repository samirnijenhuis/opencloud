<?php

namespace Tests\Fakes;

use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Stands in for git and buildctl. A fake checkout writes $repository into the build folder.
 */
final class FakeProcesses
{
    /** @var list<array{command: list<string>, path: ?string, env: array<string, string>}> */
    public array $ran = [];

    /** @var array<string, string> path => contents */
    public array $repository = [];

    /** @var list<string> */
    public array $buildOutput = [];

    public int $buildExitCode = 0;

    public string $lsRemote = '';

    /** @var list<string> the Dockerfile text that each build saw */
    public array $dockerfiles = [];

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
                file_put_contents("{$process->path}/{$path}", $contents);
            }
        }

        if ($command[0] === 'git' && $command[1] === 'ls-remote') {
            return Process::result($this->lsRemote);
        }

        if ($command[0] === 'buildctl') {
            $filename = substr(collect($command)->first(fn (string $arg) => str_starts_with($arg, 'filename=')), strlen('filename='));
            $this->dockerfiles[] = file_get_contents("{$process->path}/{$filename}");

            return (new FakeProcessDescription)->output($this->buildOutput)->exitCode($this->buildExitCode);
        }

        return Process::result();
    }
}

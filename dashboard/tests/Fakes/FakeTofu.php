<?php

namespace Tests\Fakes;

use App\Tofu\Runner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Stands in for the tofu binary. It records each call with the variables file that the call found on disk.
 */
final class FakeTofu
{
    /** @var list<array{args: list<string>, path: ?string, env: array<string, string>, var_file_mode: ?string, variables: ?array<string, mixed>}> */
    public array $ran = [];

    /** @var array<string, mixed> name => value, what `tofu output -json` returns */
    public array $outputs = [];

    /** @var array<string, list<string>> subcommand => output lines */
    public array $lines = [];

    /** The subcommand that fails, for example "apply". */
    public ?string $failOn = null;

    /** How many calls of $failOn fail before one succeeds. */
    public int $failTimes = PHP_INT_MAX;

    public string $error = 'Error: Error creating Kubernetes cluster: POST https://api.digitalocean.com/v2/kubernetes/clusters: 422 Region is not available';

    public static function install(): self
    {
        $fake = new self;
        Process::fake(['*' => fn (PendingProcess $process) => $fake->handle($process)]);

        return $fake;
    }

    /** @return list<list<string>> the arguments of each call, without the binary */
    public function commands(): array
    {
        return array_column($this->ran, 'args');
    }

    private function handle(PendingProcess $process): mixed
    {
        $args = array_slice($process->command, 1);
        $varFile = $process->path.'/'.Runner::VAR_FILE;
        clearstatcache();

        $this->ran[] = [
            'args' => $args,
            'path' => $process->path,
            'env' => $process->environment,
            'var_file_mode' => is_file($varFile) ? substr(sprintf('%o', fileperms($varFile)), -3) : null,
            'variables' => is_file($varFile) ? json_decode(file_get_contents($varFile), true) : null,
        ];

        if ($args[0] === $this->failOn && $this->failTimes-- > 0) {
            return Process::describe()->output($this->lines[$args[0]] ?? [])->errorOutput($this->error)->exitCode(1);
        }

        if ($args[0] === 'output') {
            return Process::result(json_encode(array_map(fn (mixed $value) => ['sensitive' => false, 'type' => 'string', 'value' => $value], $this->outputs)));
        }

        return Process::describe()->output($this->lines[$args[0]] ?? [])->exitCode(0);
    }
}

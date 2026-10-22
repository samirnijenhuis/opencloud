<?php

namespace App\Kube;

use App\Models\Cluster;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Records every kubectl call instead of running it. Tests script answers with respond().
 */
class FakeKubectl extends KubectlFactory
{
    /** @var list<array{cluster: string, args: list<string>, stdin: ?string}> */
    public array $calls = [];

    /** @var list<array{0: string, 1: Closure(list<string>, ?string): KubectlResult}> */
    private array $responders = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(KubectlFactory::class, $fake);

        return $fake;
    }

    public function for(Cluster $cluster): Kubectl
    {
        $fake = $this;

        return new class($cluster, $fake) extends Kubectl
        {
            public function __construct(Cluster $cluster, private FakeKubectl $fake)
            {
                parent::__construct($cluster);
            }

            protected function process(array $command, mixed $stdin, int $timeout, mixed $output = null): KubectlResult
            {
                return $this->fake->record($this->cluster->name, $command, $stdin, $output);
            }
        };
    }

    /**
     * Answers each call whose arguments, joined by spaces, start with $prefix.
     * The newest matching responder wins.
     *
     * @param  KubectlResult|Closure(list<string>, ?string): KubectlResult  $result
     */
    public function respond(string $prefix, KubectlResult|Closure $result): self
    {
        $this->responders[] = [$prefix, $result instanceof Closure ? $result : fn () => $result];

        return $this;
    }

    /**
     * A stream on stdin is read into the recorded call. Output goes to the $output stream when one is given.
     *
     * @param  list<string>  $command
     * @param  string|resource|null  $stdin
     * @param  resource|null  $output
     */
    public function record(string $cluster, array $command, mixed $stdin, mixed $output = null): KubectlResult
    {
        $args = array_values(array_filter(array_slice($command, 1), fn (string $arg) => ! str_starts_with($arg, '--kubeconfig=')));
        $stdin = is_resource($stdin) ? stream_get_contents($stdin) : $stdin;
        $this->calls[] = ['cluster' => $cluster, 'args' => $args, 'stdin' => $stdin];
        $line = implode(' ', $args);
        $result = new KubectlResult(0, Str::startsWith($line, 'get ') ? '' : 'ok');

        foreach (array_reverse($this->responders) as [$prefix, $responder]) {
            if (str_starts_with($line, $prefix)) {
                $result = $responder($args, $stdin);
                break;
            }
        }

        if ($output !== null) {
            fwrite($output, $result->output);
        }

        return $result;
    }

    /** @return list<array{cluster: string, args: list<string>, stdin: ?string}> */
    public function callsStartingWith(string $prefix): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => str_starts_with(implode(' ', $call['args']), $prefix)));
    }

    /** @return list<array<string, mixed>> */
    public function appliedManifests(): array
    {
        return collect($this->callsStartingWith('apply '))
            ->flatMap(fn (array $call) => json_decode($call['stdin'], true)['items'])
            ->values()
            ->all();
    }

    public function assertRan(string $prefix): void
    {
        Assert::assertNotEmpty($this->callsStartingWith($prefix), "Expected a kubectl call starting with [{$prefix}].");
    }
}

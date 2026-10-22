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

            protected function process(array $command, ?string $stdin, int $timeout): KubectlResult
            {
                return $this->fake->record($this->cluster->name, $command, $stdin);
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

    /** @param  list<string>  $command */
    public function record(string $cluster, array $command, ?string $stdin): KubectlResult
    {
        $args = array_values(array_filter(array_slice($command, 1), fn (string $arg) => ! str_starts_with($arg, '--kubeconfig=')));
        $this->calls[] = ['cluster' => $cluster, 'args' => $args, 'stdin' => $stdin];
        $line = implode(' ', $args);

        foreach (array_reverse($this->responders) as [$prefix, $responder]) {
            if (str_starts_with($line, $prefix)) {
                return $responder($args, $stdin);
            }
        }

        return new KubectlResult(0, Str::startsWith($line, 'get ') ? '' : 'ok');
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

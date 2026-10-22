<?php

namespace App\Kube;

use App\Enums\ClusterKind;
use App\Models\Cluster;
use Symfony\Component\Process\Process;

class Kubectl
{
    public function __construct(public readonly Cluster $cluster) {}

    public static function for(Cluster $cluster): static
    {
        return app(KubectlFactory::class)->for($cluster);
    }

    /** @param  list<string>  $args */
    public function run(array $args, ?string $stdin = null, int $timeout = 120): KubectlResult
    {
        if ($this->cluster->kind === ClusterKind::Local) {
            $kubeconfig = config('opencloud.local_kubeconfig');

            return $this->process($this->command($args, $kubeconfig ?: null), $stdin, $timeout);
        }

        $path = tempnam(sys_get_temp_dir(), 'kubeconfig-');
        chmod($path, 0600);
        file_put_contents($path, $this->cluster->kubeconfig);

        try {
            return $this->process($this->command($args, $path), $stdin, $timeout);
        } finally {
            unlink($path);
        }
    }

    /** @param  list<array<string, mixed>>  $manifests */
    public function apply(array $manifests, int $timeout = 120): KubectlResult
    {
        $list = ['apiVersion' => 'v1', 'kind' => 'List', 'items' => $manifests];

        return $this->run(
            ['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'],
            json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $timeout,
        )->throw();
    }

    public function delete(string $kind, string $name, ?string $namespace = null, bool $wait = false): KubectlResult
    {
        return $this->run([
            'delete', $kind, $name, ...$this->namespaceArgs($namespace), '--ignore-not-found', '--wait='.($wait ? 'true' : 'false'),
        ])->throw();
    }

    /**
     * @param  array<string, string>  $selector
     * @return array<mixed>|null
     */
    public function get(string $kind, ?string $name = null, ?string $namespace = null, array $selector = []): ?array
    {
        $args = ['get', $kind];

        if ($name !== null) {
            $args[] = $name;
        }

        if ($selector !== []) {
            $args[] = '--selector='.self::selector($selector);
        }

        $result = $this->run([...$args, ...$this->namespaceArgs($namespace), '--ignore-not-found', '-o', 'json']);
        $result->throw();

        return trim($result->output) === '' ? null : $result->json();
    }

    /** @param  list<string>  $command */
    public function exec(string $namespace, string $target, array $command, ?string $stdin = null, ?string $container = null, int $timeout = 300): KubectlResult
    {
        $args = ['exec', ...($stdin !== null ? ['-i'] : []), $target, '--namespace='.$namespace];

        if ($container !== null) {
            $args[] = '--container='.$container;
        }

        return $this->run([...$args, '--', ...$command], $stdin, $timeout);
    }

    /** @param  array<string, string>  $selector */
    public function logs(string $namespace, array $selector, int $lines = 200, ?string $container = null): string
    {
        $args = ['logs', '--namespace='.$namespace, '--selector='.self::selector($selector), '--tail='.$lines, '--prefix', '--max-log-requests=20'];

        if ($container !== null) {
            $args[] = '--container='.$container;
        }

        return $this->run($args)->output;
    }

    public function rolloutStatus(string $namespace, string $kind, string $name, int $timeout = 300): KubectlResult
    {
        return $this->run(['rollout', 'status', "{$kind}/{$name}", '--namespace='.$namespace, '--timeout='.$timeout.'s'], timeout: $timeout + 30);
    }

    /** @param  array<string, string>  $selector */
    public static function selector(array $selector): string
    {
        return collect($selector)->map(fn (string $value, string $key) => "{$key}={$value}")->implode(',');
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    protected function command(array $args, ?string $kubeconfig): array
    {
        return [config('opencloud.kubectl_binary', 'kubectl'), ...($kubeconfig !== null ? ['--kubeconfig='.$kubeconfig] : []), ...$args];
    }

    /** @param  list<string>  $command */
    protected function process(array $command, ?string $stdin, int $timeout): KubectlResult
    {
        $process = new Process($command, timeout: $timeout);

        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        $process->run();

        return new KubectlResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /** @return list<string> */
    private function namespaceArgs(?string $namespace): array
    {
        return $namespace !== null ? ['--namespace='.$namespace] : [];
    }
}

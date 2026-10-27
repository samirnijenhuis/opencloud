<?php

namespace App\Domains\Environments;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;
use App\Support\Kube\Labels;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads what runs right now from Kubernetes, for the UI to poll. Each read makes one kubectl call per resource type,
 * never one per pod, and the answer stays in the cache for 10 seconds.
 * A failure gives the state "unknown", so the UI never gets an error page.
 */
final class LiveStatus
{
    private const CACHE_SECONDS = 10;

    private const TIMEOUT_SECONDS = 15;

    /** Finished Job pods hold no resources. */
    private const NOT_FINISHED = '--field-selector=status.phase!=Succeeded,status.phase!=Failed';

    /**
     * @return array{
     *     state: 'ok'|'unknown',
     *     error: ?string,
     *     processes: list<array{name: string, desired: int, ready: int, restarts: int, cpu_millicores: ?int, memory_mib: ?int}>,
     *     scheduled: list<array{name: string, last_run_at: ?string, last_run_failed: bool}>,
     *     requests: array{cpu_millicores: int, memory_mib: int}|null,
     *     checked_at: string,
     * }
     */
    public function environment(Environment $environment): array
    {
        return Cache::remember("live-status:environment:{$environment->id}", self::CACHE_SECONDS, fn () => $this->read(
            fn () => $this->readEnvironment($environment),
            ['processes' => [], 'scheduled' => [], 'requests' => null],
        ));
    }

    /**
     * @return array{
     *     state: 'ok'|'unknown',
     *     error: ?string,
     *     nodes: ?int,
     *     allocatable: array{cpu_millicores: int, memory_mib: int}|null,
     *     requested: array{cpu_millicores: int, memory_mib: int}|null,
     *     checked_at: string,
     * }
     */
    public function cluster(Cluster $cluster): array
    {
        return Cache::remember("live-status:cluster:{$cluster->id}", self::CACHE_SECONDS, fn () => $this->read(
            fn () => $this->readCluster($cluster),
            ['nodes' => null, 'allocatable' => null, 'requested' => null],
        ));
    }

    /**
     * @param  Closure(): array<string, mixed>  $read
     * @param  array<string, mixed>  $unknown
     * @return array<string, mixed>
     */
    private function read(Closure $read, array $unknown): array
    {
        try {
            $state = ['state' => 'ok', 'error' => null, ...$read()];
        } catch (Throwable $e) {
            Log::warning('Opencloud could not read the live status.', ['error' => $e->getMessage()]);
            $state = ['state' => 'unknown', 'error' => 'Opencloud could not read the status from the cluster.', ...$unknown];
        }

        return [...$state, 'checked_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function readEnvironment(Environment $environment): array
    {
        $kubectl = Kubectl::for($environment->application->cluster);
        $namespace = $environment->kubeNamespace();
        $deployments = $this->items($kubectl, ['get', 'deployments', "--namespace={$namespace}"]);
        $cronJobs = $this->items($kubectl, ['get', 'cronjobs', "--namespace={$namespace}"]);
        $pods = $this->items($kubectl, ['get', 'pods', "--namespace={$namespace}", self::NOT_FINISHED]);
        $usage = $this->usage($kubectl, "/apis/metrics.k8s.io/v1beta1/namespaces/{$namespace}/pods");

        $processes = [];

        foreach ($deployments as $deployment) {
            $name = $deployment['metadata']['labels'][Labels::PROCESS] ?? null;

            if ($name === null) {
                continue;
            }

            $own = collect($pods)->filter(fn (array $pod) => ($pod['metadata']['labels'][Labels::PROCESS] ?? null) === $name);
            // A pod that started seconds ago has no sample yet. A process without any sample has no usage to show.
            $samples = $own->map(fn (array $pod) => $usage[$pod['metadata']['name']] ?? null)->filter();

            $processes[] = [
                'name' => $name,
                'desired' => (int) ($deployment['spec']['replicas'] ?? 0),
                'ready' => (int) ($deployment['status']['readyReplicas'] ?? 0),
                'restarts' => $own->sum(fn (array $pod) => collect($pod['status']['containerStatuses'] ?? [])->sum('restartCount')),
                'cpu_millicores' => $samples->isEmpty() ? null : $samples->sum('cpu_millicores'),
                'memory_mib' => $samples->isEmpty() ? null : $samples->sum('memory_mib'),
            ];
        }

        usort($processes, fn (array $a, array $b) => [$a['name'] !== 'web', $a['name']] <=> [$b['name'] !== 'web', $b['name']]);

        return ['processes' => $processes, 'scheduled' => $this->scheduled($cronJobs), 'requests' => $this->requests($pods)];
    }

    /**
     * The last run failed when it no longer runs and no success came after its start. With concurrencyPolicy Allow,
     * a newer run can end before an older one, so this reads the newest start, not each run.
     *
     * @param  list<array<string, mixed>>  $cronJobs
     * @return list<array{name: string, last_run_at: ?string, last_run_failed: bool}>
     */
    private function scheduled(array $cronJobs): array
    {
        return collect($cronJobs)
            ->filter(fn (array $cronJob) => isset($cronJob['metadata']['labels'][Labels::PROCESS]))
            ->map(function (array $cronJob) {
                $started = $cronJob['status']['lastScheduleTime'] ?? null;
                $succeeded = $cronJob['status']['lastSuccessfulTime'] ?? null;
                $running = ($cronJob['status']['active'] ?? []) !== [];

                return [
                    'name' => $cronJob['metadata']['labels'][Labels::PROCESS],
                    'last_run_at' => $started,
                    'last_run_failed' => $started !== null && ! $running && ($succeeded === null || strtotime($succeeded) < strtotime($started)),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function readCluster(Cluster $cluster): array
    {
        $kubectl = Kubectl::for($cluster);
        $nodes = $this->items($kubectl, ['get', 'nodes']);
        $pods = $this->items($kubectl, ['get', 'pods', '--all-namespaces', self::NOT_FINISHED]);

        return [
            'nodes' => count($nodes),
            'allocatable' => [
                'cpu_millicores' => collect($nodes)->sum(fn (array $node) => Quantity::millicores((string) ($node['status']['allocatable']['cpu'] ?? '0'))),
                'memory_mib' => collect($nodes)->sum(fn (array $node) => Quantity::mebibytes((string) ($node['status']['allocatable']['memory'] ?? '0'))),
            ],
            'requested' => $this->requests($pods),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     * @return array{cpu_millicores: int, memory_mib: int}
     */
    private function requests(array $pods): array
    {
        $containers = collect($pods)->flatMap(fn (array $pod) => $pod['spec']['containers'] ?? []);

        return [
            'cpu_millicores' => $containers->sum(fn (array $container) => Quantity::millicores((string) ($container['resources']['requests']['cpu'] ?? '0'))),
            'memory_mib' => $containers->sum(fn (array $container) => Quantity::mebibytes((string) ($container['resources']['requests']['memory'] ?? '0'))),
        ];
    }

    /**
     * Without a metrics server the API answers with an error. That is a normal state, so it gives null.
     *
     * @return array<string, array{cpu_millicores: int, memory_mib: int}>|null keyed by pod name
     */
    private function usage(Kubectl $kubectl, string $path): ?array
    {
        $result = $kubectl->run(['get', '--raw', $path], timeout: self::TIMEOUT_SECONDS);

        if (! $result->ok()) {
            return null;
        }

        return collect($result->json()['items'] ?? [])->mapWithKeys(fn (array $pod) => [$pod['metadata']['name'] => [
            'cpu_millicores' => collect($pod['containers'] ?? [])->sum(fn (array $container) => Quantity::millicores((string) $container['usage']['cpu'])),
            'memory_mib' => collect($pod['containers'] ?? [])->sum(fn (array $container) => Quantity::mebibytes((string) $container['usage']['memory'])),
        ]])->all();
    }

    /**
     * @param  list<string>  $args
     * @return list<array<string, mixed>>
     */
    private function items(Kubectl $kubectl, array $args): array
    {
        return $kubectl->run([...$args, '-o', 'json'], timeout: self::TIMEOUT_SECONDS)->throw()->json()['items'] ?? [];
    }
}

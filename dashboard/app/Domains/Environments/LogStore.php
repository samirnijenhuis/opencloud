<?php

namespace App\Domains\Environments;

use App\Domains\Clusters\ClusterPartsManifests;
use App\Domains\Clusters\Models\Cluster;
use App\Support\Kube\Kubectl;
use App\Support\Kube\KubectlException;
use App\Support\Kube\KubectlResult;
use Carbon\CarbonInterface;

/**
 * The Loki of a cluster. The NetworkPolicy of Loki refuses the dashboard, so each request runs wget in the query container
 * of the Loki pod against 127.0.0.1.
 */
final readonly class LogStore
{
    /**
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}> the streams, each value a time in nanoseconds and a line
     *
     * @throws KubectlException when Loki does not answer
     */
    public function lines(Cluster $cluster, string $query, CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        return $this->send($cluster, 'query_range', [
            'query' => $query,
            'start' => $from->toRfc3339String(),
            'end' => $to->toRfc3339String(),
            'limit' => $limit,
            'direction' => 'backward',
        ])->json()['data']['result'];
    }

    /**
     * Loki drops the lines from each query within 5 minutes. The compactor deletes them from the disk after
     * delete_request_cancel_period, 24 hours by default, and the old chunk 2 hours later.
     *
     * @throws KubectlException when Loki does not answer or refuses the request, for example for an end in its future
     */
    public function delete(Cluster $cluster, string $selector, CarbonInterface $from, CarbonInterface $to): void
    {
        $this->send($cluster, 'delete', [
            'query' => $selector,
            'start' => $from->toRfc3339String(),
            'end' => $to->toRfc3339String(),
        ], post: true);
    }

    /** A LogQL string uses the escapes of a Go string, which JSON also uses. */
    public static function quote(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<string, string|int>  $parameters */
    private function send(Cluster $cluster, string $endpoint, array $parameters, bool $post = false): KubectlResult
    {
        $url = 'http://127.0.0.1:3100/loki/api/v1/'.$endpoint.'?'.http_build_query($parameters);

        return Kubectl::for($cluster)
            ->exec(
                ClusterPartsManifests::NAMESPACE,
                'deploy/'.ClusterPartsManifests::LOKI,
                ['wget', '-q', '-T', '20', '-O-', ...($post ? ['--post-data='] : []), $url],
                container: ClusterPartsManifests::LOKI_QUERY_CONTAINER,
                timeout: 30,
            )
            ->throw();
    }
}

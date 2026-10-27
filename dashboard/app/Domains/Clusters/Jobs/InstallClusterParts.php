<?php

namespace App\Domains\Clusters\Jobs;

use App\Domains\Clusters\ClusterPartsManifests;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Routing\DnsResolver;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\Kubectl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies the opencloud parts to a cluster and waits until Caddy runs and its load balancer has an address.
 * Each try applies the full parts again, so a try after a crash or a second job converges to the same state.
 * The job releases itself between tries, so the wait for a cloud load balancer never holds a queue worker.
 */
class InstallClusterParts implements ShouldQueue
{
    use Queueable;

    public const RETRY_SECONDS = 15;

    /** With 15 seconds between tries, the load balancer gets 15 minutes. */
    public const MAX_TRIES = 60;

    public int $tries = self::MAX_TRIES;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Cluster $cluster) {}

    public function handle(DnsResolver $resolver): void
    {
        $cluster = $this->cluster;

        try {
            $kubectl = Kubectl::for($cluster);
            $kubectl->apply(ClusterPartsManifests::fromConfig()->all());
            $caddy = $kubectl->get('deployment', 'caddy', ClusterPartsManifests::NAMESPACE);
            $service = $kubectl->get('service', 'caddy', ClusterPartsManifests::NAMESPACE);
        } catch (Throwable $e) {
            $this->markFailed($cluster, $e->getMessage());

            return;
        }

        $ip = self::ingressIp($service['status']['loadBalancer']['ingress'] ?? [], $resolver);
        $running = ($caddy['status']['availableReplicas'] ?? 0) >= 1;

        if ($ip !== null && $running) {
            $cluster->update(['status' => ClusterStatus::Ready, 'error' => null, 'ingress_ip' => $ip, 'parts_version' => config('opencloud.version')]);
            SyncCaddy::dispatch($cluster);

            return;
        }

        if ($this->attempts() < self::MAX_TRIES) {
            $this->release(self::RETRY_SECONDS);

            return;
        }

        $this->markFailed($cluster, $running
            ? 'The load balancer of Caddy got no address in 15 minutes. Check that the cluster can create a Service of type LoadBalancer.'
            : 'Caddy did not start in 15 minutes. Read its events with: kubectl -n opencloud describe pod -l app.kubernetes.io/name=caddy');
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed($this->cluster, $e?->getMessage() ?? 'The install job failed.');
    }

    /**
     * Domains point at an IPv4 address. Some cloud load balancers have a host name instead, so the job resolves it.
     *
     * @param  list<array{ip?: string, hostname?: string}>  $ingress  the status.loadBalancer.ingress list of the Service
     */
    private static function ingressIp(array $ingress, DnsResolver $resolver): ?string
    {
        foreach ($ingress as $entry) {
            if (filter_var($entry['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $entry['ip'];
            }
        }

        foreach ($ingress as $entry) {
            if (isset($entry['hostname']) && ($ips = $resolver->ipv4($entry['hostname'])) !== []) {
                return $ips[0];
            }
        }

        return null;
    }

    private function markFailed(Cluster $cluster, string $error): void
    {
        $cluster->update(['status' => ClusterStatus::Failed, 'error' => $error]);
    }
}

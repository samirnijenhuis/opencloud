<?php

namespace App\Domains\Routing;

use App\Domains\Clusters\Models\Cluster;
use App\Support\Kube\Kubectl;

/**
 * Reads the request counters that Caddy keeps for each hostname. Caddy counts from its start, so a restart sets each
 * counter back to zero.
 */
final class CaddyMetrics
{
    /**
     * @return array<string, int>|null the requests of each app hostname since Caddy started, or null when Caddy did not answer
     */
    public function requestCounts(Cluster $cluster): ?array
    {
        $result = Kubectl::for($cluster)->exec('opencloud', 'deploy/caddy', ['wget', '-q', '-O-', 'http://127.0.0.1:2019/metrics'], timeout: 60);

        if (! $result->ok()) {
            return null;
        }

        // A line looks like: caddy_http_requests_total{handler="reverse_proxy",host="shop.example.com",server="apps"} 42
        preg_match_all('/^caddy_http_requests_total\{([^}]*)\} (\S+)$/m', $result->output, $lines, PREG_SET_ORDER);
        $counts = [];

        foreach ($lines as [, $labels, $value]) {
            preg_match_all('/(\w+)="([^"]*)"/', $labels, $pairs);
            $label = array_combine($pairs[1], $pairs[2]);

            if (($label['server'] ?? null) !== CaddyConfig::APP_SERVER || ! isset($label['host'])) {
                continue;
            }

            // The label is the Host header as the client sent it, so "shop.example.com:443" is the same hostname.
            $host = preg_replace('/:\d+$/', '', $label['host']);
            $counts[$host] = ($counts[$host] ?? 0) + (int) $value;
        }

        return $counts;
    }
}

<?php

namespace App\Build;

use App\Enums\ClusterKind;
use App\Kube\Kubectl;
use App\Models\Cluster;
use RuntimeException;

/**
 * BuildKit listens on its pod's Unix socket only. buildctl reaches it through kubectl exec,
 * so BuildKit has no network port that another pod could use.
 */
class BuildKit
{
    public static function address(): string
    {
        $configured = config('opencloud.buildkit_addr');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $local = Cluster::query()->where('kind', ClusterKind::Local)->firstOrFail();
        $pods = Kubectl::for($local)->get('pods', namespace: 'opencloud', selector: ['app.kubernetes.io/name' => 'buildkitd'])['items'] ?? [];

        foreach ($pods as $pod) {
            if (($pod['status']['phase'] ?? null) === 'Running' && ! isset($pod['metadata']['deletionTimestamp'])) {
                return "kube-pod://{$pod['metadata']['name']}?namespace=opencloud";
            }
        }

        throw new RuntimeException('BuildKit is not running. Check it with: sudo opencloud status');
    }
}

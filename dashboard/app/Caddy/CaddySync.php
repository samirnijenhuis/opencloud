<?php

namespace App\Caddy;

use App\Kube\Kubectl;
use App\Models\Cluster;

final class CaddySync
{
    /** Busybox resolves localhost to ::1, and Caddy listens on 127.0.0.1 only. */
    public const LOAD_COMMAND = 'cat > /tmp/caddy.json && wget -q -O- --header "Content-Type: application/json" --post-file /tmp/caddy.json http://127.0.0.1:2019/load';

    public function sync(Cluster $cluster): void
    {
        $json = json_encode(CaddyConfig::fromConfig()->forCluster($cluster), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Kubectl::for($cluster)->exec('opencloud', 'deploy/caddy', ['sh', '-c', self::LOAD_COMMAND], stdin: $json, timeout: 60)->throw();
    }
}

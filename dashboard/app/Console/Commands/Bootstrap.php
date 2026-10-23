<?php

namespace App\Console\Commands;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Models\Cluster;
use Illuminate\Console\Command;

class Bootstrap extends Command
{
    protected $signature = 'opencloud:bootstrap';

    protected $description = 'Make sure the records that each install needs exist';

    public function handle(): int
    {
        $publicIp = config('opencloud.public_ip');

        $cluster = Cluster::updateOrCreate(
            ['kind' => ClusterKind::Local],
            // The installer puts the parts on the local cluster, so it is ready once the dashboard runs.
            ['name' => 'local', 'status' => ClusterStatus::Ready, 'parts_version' => config('opencloud.version'), ...($publicIp ? ['ingress_ip' => $publicIp] : [])],
        );

        $this->info("The local cluster is cluster {$cluster->id}.");

        return self::SUCCESS;
    }
}

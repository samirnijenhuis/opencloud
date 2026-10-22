<?php

namespace App\Console\Commands;

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
            ['name' => 'local', ...($publicIp ? ['ingress_ip' => $publicIp] : [])],
        );

        $this->info("The local cluster is cluster {$cluster->id}.");

        return self::SUCCESS;
    }
}

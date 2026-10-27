<?php

namespace App\Console\Commands;

use App\Domains\Clusters\Actions\ReinstallClusterParts;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use Illuminate\Console\Command;

class Bootstrap extends Command
{
    protected $signature = 'opencloud:bootstrap';

    protected $description = 'Make sure the records that each install needs exist';

    public function handle(ReinstallClusterParts $reinstallClusterParts): int
    {
        $publicIp = config('opencloud.public_ip');
        $version = config('opencloud.version');

        $cluster = Cluster::updateOrCreate(
            ['kind' => ClusterKind::LOCAL],
            // The installer puts the parts on the local cluster, so it is ready once the dashboard runs.
            ['name' => 'local', 'status' => ClusterStatus::READY, 'parts_version' => $version, ...($publicIp ? ['ingress_ip' => $publicIp] : [])],
        );

        $this->info("The local cluster is cluster {$cluster->id}.");

        // After an upgrade, the other clusters still run the parts of the old release. A cluster that never got its parts, or that installs now, keeps its state.
        Cluster::query()
            ->whereNot('kind', ClusterKind::LOCAL)
            ->whereIn('status', [ClusterStatus::READY, ClusterStatus::FAILED])
            ->whereNotNull('parts_version')
            ->whereNot('parts_version', $version)
            ->get()
            ->each(function (Cluster $cluster) use ($reinstallClusterParts, $version) {
                $reinstallClusterParts->execute($cluster, null);
                $this->info("Updating the parts on cluster {$cluster->name} from {$cluster->parts_version} to {$version}.");
            });

        return self::SUCCESS;
    }
}

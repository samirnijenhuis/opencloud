<?php

namespace App\Domains\Upgrades\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Members\Models\User;
use App\Domains\Upgrades\UpgradeManifests;
use App\Domains\Upgrades\UpgradeStatus;
use App\Support\Kube\Kubectl;
use Illuminate\Validation\ValidationException;

/**
 * Starts `opencloud upgrade <target>` on the host, the same command that the owner can run with sudo.
 */
class StartUpgrade
{
    public function __construct(private UpgradeStatus $status) {}

    /** @throws ValidationException when an upgrade runs, or when a deployment builds or deploys now */
    public function execute(User $by, string $target): void
    {
        // Only the host writes "running". A second start would write "starting" over it, and the progress page would
        // report that the running upgrade never started.
        if (($this->status->last()['state'] ?? null) === 'running') {
            throw ValidationException::withMessages(['target' => 'An update runs now. If it stopped, run "sudo opencloud upgrade" on the server.']);
        }

        // The upgrade recreates the dashboard pod, which stops the builder container and fails its deployment.
        if (Deployment::query()->whereIn('status', [DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING])->exists()) {
            throw ValidationException::withMessages(['target' => 'A deployment runs now. Wait until it ends, then update.']);
        }

        $kubectl = Kubectl::for(Cluster::query()->where('kind', ClusterKind::LOCAL)->firstOrFail());
        $dashboard = $kubectl->get('deployment', 'dashboard', 'opencloud');
        $image = collect($dashboard['spec']['template']['spec']['containers'])->firstWhere('name', 'web')['image'];
        $from = (string) config('opencloud.version');

        // The page that waits for the upgrade reads this state, so it never shows the result of an earlier upgrade.
        $this->status->starting($from, $target);
        $kubectl->apply([UpgradeManifests::job($target, $image, now()->getTimestamp())]);

        AuditEvent::record($by, 'opencloud.upgrade_started', null, ['from' => $from, 'target' => $target]);
    }
}

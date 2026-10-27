<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\Kubectl;
use Illuminate\Support\Facades\DB;

/**
 * Removes the preview of a closed pull request: its namespace, its data, its domains, and its records.
 * Each step converges, so a run after a crash finishes the job.
 */
class ClosePreview
{
    /** @return bool false while a deployment of the preview still builds or rolls out, so the caller tries again later */
    public function execute(Application $application, int $number): bool
    {
        $preview = $application->environments()->where('kind', EnvironmentKind::PREVIEW)->where('pr_number', $number)->first();

        if ($preview === null) {
            return true;
        }

        // A running deployment would apply its manifests into the namespace again after the delete.
        if ($preview->deployments()->whereIn('status', [DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING])->exists()) {
            return false;
        }

        Kubectl::for($application->cluster)->delete('namespace', $preview->kubeNamespace());

        DB::transaction(function () use ($application, $preview, $number) {
            foreach ($preview->datastoreAttachments()->orderBy('id')->get() as $attachment) {
                Provisioners::of($attachment->datastore)->detach($attachment, null, destroyData: true);
            }

            $hostnames = $preview->domains()->orderBy('id')->pluck('hostname');

            foreach ($hostnames as $hostname) {
                AuditEvent::record(null, 'domain.removed', $application, ['environment_id' => $preview->id, 'hostname' => $hostname]);
            }

            // The env values, deployments, and domains go with the environment through the foreign keys.
            $preview->delete();

            AuditEvent::record(null, 'preview.destroyed', $application, [
                'environment_id' => $preview->id,
                'pr_number' => $number,
                'namespace' => $preview->kubeNamespace(),
            ]);

            if ($hostnames->isNotEmpty()) {
                SyncCaddy::dispatch($application->cluster)->afterCommit();
            }
        });

        return true;
    }
}

<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Support\Kube\Kubectl;
use App\Support\Kube\KubectlException;
use Illuminate\Validation\ValidationException;

/**
 * Removes an environment that a user added: its namespace, its domains, the data of its resources, and its records.
 * Each step converges, so a delete after a failed one finishes the job.
 */
class DeleteEnvironment
{
    public function __construct(private RemoveDomain $removeDomain) {}

    /**
     * @throws ValidationException while a deployment builds or rolls out, or when the cluster or Cloudflare refuses a delete
     */
    public function execute(Environment $environment, User $by): void
    {
        // A running deployment would apply its manifests into the namespace again after the delete.
        if ($environment->deployments()->whereIn('status', [DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING])->exists()) {
            throw ValidationException::withMessages(['environment' => "A deployment of {$environment->name} runs. Wait until it ends, then delete the environment."]);
        }

        $application = $environment->application;

        try {
            Kubectl::for($application->cluster)->delete('namespace', $environment->kubeNamespace());
        } catch (KubectlException $e) {
            throw ValidationException::withMessages(['environment' => "Opencloud could not delete the namespace. {$e->getMessage()} Try again."]);
        }

        // RemoveDomain also deletes a Cloudflare record that opencloud created and syncs Caddy.
        foreach ($environment->domains()->orderBy('id')->get() as $domain) {
            $this->removeDomain->execute($domain, $by);
        }

        foreach ($environment->datastoreAttachments()->with('datastore')->orderBy('id')->get() as $attachment) {
            Provisioners::of($attachment->datastore)->detach($attachment, $by, destroyData: true);
        }

        // The env values, the processes, the deployments, and the command runs go with the environment through the foreign keys.
        $environment->delete();

        AuditEvent::record($by, 'environment.deleted', $application, [
            'environment_id' => $environment->id,
            'name' => $environment->name,
            'namespace' => $environment->kubeNamespace(),
        ]);
    }
}

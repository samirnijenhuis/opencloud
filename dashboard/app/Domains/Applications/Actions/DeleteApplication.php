<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\DeleteEnvironment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Removes an application with each of its environments. DeleteEnvironment converges, so a delete after a refused one
 * finishes the job.
 */
class DeleteApplication
{
    private const ORDER = [EnvironmentKind::PREVIEW, EnvironmentKind::PERMANENT, EnvironmentKind::PREVIEW_TEMPLATE];

    public function __construct(private DeleteEnvironment $deleteEnvironment) {}

    /**
     * @throws ValidationException from DeleteEnvironment, while a deployment runs, while OpenTofu destroys a managed resource,
     *                             or when the cluster or Cloudflare refuses a delete
     */
    public function execute(Application $application, User $by): void
    {
        // OpenPreview skips an application with previews off. A pull request that opens during the delete would else get
        // a preview after the previews went, and the cascade at the end would leave its namespace on the cluster.
        $application->update(['previews_enabled' => false]);

        foreach (self::ORDER as $kind) {
            foreach ($application->environments()->where('kind', $kind)->orderBy('id')->get() as $environment) {
                $this->deleteEnvironment->execute($environment, $by);
            }
        }

        $application->delete();

        AuditEvent::record($by, 'application.deleted', $application, [
            'name' => $application->name,
            'repository' => $application->repository,
        ]);
    }
}

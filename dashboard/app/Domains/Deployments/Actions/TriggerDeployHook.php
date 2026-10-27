<?php

namespace App\Domains\Deployments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\DeployHook;
use App\Domains\Deployments\Models\Deployment;
use InvalidArgumentException;
use RuntimeException;

class TriggerDeployHook
{
    public function __construct(private StartDeployment $startDeployment) {}

    /**
     * @return Deployment|null null when no enabled hook has this token
     *
     * @throws InvalidArgumentException|RuntimeException when the environment cannot deploy its branch
     */
    public function execute(string $token, ?string $ip): ?Deployment
    {
        $environment = DeployHook::find($token);

        if ($environment === null) {
            return null;
        }

        $deployment = $this->startDeployment->execute($environment, null);

        AuditEvent::record(null, 'deploy_hook.triggered', $environment, ['deployment_id' => $deployment->id, 'ip' => $ip]);

        return $deployment;
    }
}

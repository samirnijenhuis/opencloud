<?php

namespace App\Domains\Deployments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\DeployHook;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use InvalidArgumentException;

class EnableDeployHook
{
    /**
     * Keeps the token of an earlier hook, so a CI job that knows the URL works again. RegenerateDeployHook replaces it.
     *
     * @throws InvalidArgumentException for the preview template, which is never deployed
     */
    public function execute(Environment $environment, User $by): Environment
    {
        if ($environment->kind === EnvironmentKind::PREVIEW_TEMPLATE) {
            throw new InvalidArgumentException('The preview template is never deployed, so it has no deploy hook.');
        }

        if ($environment->deploy_hook_enabled) {
            return $environment;
        }

        $environment->deploy_hook_token ??= DeployHook::newToken();
        $environment->deploy_hook_enabled = true;
        $environment->save();

        AuditEvent::record($by, 'deploy_hook.enabled', $environment);

        return $environment;
    }
}

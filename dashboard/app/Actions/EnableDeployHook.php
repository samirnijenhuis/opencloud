<?php

namespace App\Actions;

use App\Enums\EnvironmentKind;
use App\Environments\DeployHook;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;
use InvalidArgumentException;

class EnableDeployHook
{
    /**
     * Keeps the token of an earlier hook, so a CI job that knows the URL works again. RegenerateDeployHook replaces it.
     *
     * @throws InvalidArgumentException for the preview template, which is never deployed
     */
    public function handle(Environment $environment, User $by): Environment
    {
        if ($environment->kind === EnvironmentKind::PreviewTemplate) {
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

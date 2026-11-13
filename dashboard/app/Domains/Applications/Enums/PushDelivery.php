<?php

namespace App\Domains\Applications\Enums;

/** How a push to the branch of an environment starts a deployment. */
enum PushDelivery: string
{
    case GITHUB_APP = 'github_app';
    case DEPLOY_HOOK = 'deploy_hook';
    case NOT_CONNECTED = 'not_connected';
    case OFF = 'off';
}

<?php

namespace App\Domains\Deployments\Enums;

/** The steps of a deployment in the order that `RunDeployment` runs them. The log marks the start of each one. */
enum DeploymentStep: string
{
    case PREPARING_BUILD = 'preparing_build';
    case RUNNING_BUILD = 'running_build';
    case PUSHING_APPLICATION = 'pushing_application';
    case PREPARING_DEPLOY = 'preparing_deploy';
    case RUNNING_DEPLOY_COMMANDS = 'running_deploy_commands';
    case UPDATING_STACK = 'updating_stack';
    case ROUTING_TRAFFIC = 'routing_traffic';

    /** @return 'build'|'deploy' */
    public function phase(): string
    {
        return match ($this) {
            self::PREPARING_BUILD, self::RUNNING_BUILD, self::PUSHING_APPLICATION => 'build',
            self::PREPARING_DEPLOY, self::RUNNING_DEPLOY_COMMANDS, self::UPDATING_STACK, self::ROUTING_TRAFFIC => 'deploy',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::PREPARING_BUILD => 'Preparing build environment',
            self::RUNNING_BUILD => 'Running build commands',
            self::PUSHING_APPLICATION => 'Pushing application',
            self::PREPARING_DEPLOY => 'Preparing deploy environment',
            self::RUNNING_DEPLOY_COMMANDS => 'Running deploy commands',
            self::UPDATING_STACK => 'Updating environment stack',
            self::ROUTING_TRAFFIC => 'Routing traffic to new deployment',
        };
    }
}

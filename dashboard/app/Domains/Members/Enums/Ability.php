<?php

namespace App\Domains\Members\Enums;

enum Ability
{
    /** Cloud keys, the GitHub connection, and kubeconfigs. */
    case MANAGE_CREDENTIALS;
    case MANAGE_MEMBERS;
    /** Sees the notice about a newer release, because only the owner runs commands on the server. */
    case UPGRADE_OPENCLOUD;
    /** Applications, datastores, and domains. */
    case MANAGE_APPLICATIONS;
    /** The settings, processes, resources, env values, and one-off commands of an environment. */
    case CHANGE_ENVIRONMENT;
    case DEPLOY;
    case READ_LOGS;
    /** Reads the saved secret env values in the dashboard. */
    case READ_SECRETS;
}

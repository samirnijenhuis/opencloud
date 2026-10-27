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
    case CHANGE_PROTECTED_ENVIRONMENT;
    case CHANGE_UNPROTECTED_ENVIRONMENT;
    case DEPLOY;
    case READ_LOGS;
}

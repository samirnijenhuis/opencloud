<?php

namespace App\Domains\Members\Enums;

enum Ability
{
    /** Cloud keys, the GitHub connection, and kubeconfigs. */
    case ManageCredentials;
    case ManageMembers;
    /** Sees the notice about a newer release, because only the owner runs commands on the server. */
    case UpgradeOpencloud;
    /** Applications, datastores, and domains. */
    case ManageApplications;
    case ChangeProtectedEnvironment;
    case ChangeUnprotectedEnvironment;
    case Deploy;
    case ReadLogs;
}

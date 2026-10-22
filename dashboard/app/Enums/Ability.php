<?php

namespace App\Enums;

enum Ability
{
    /** Cloud keys, the GitHub connection, and kubeconfigs. */
    case ManageCredentials;
    case ManageMembers;
    /** Applications, datastores, and domains. */
    case ManageApplications;
    case ChangeProtectedEnvironment;
    case ChangeUnprotectedEnvironment;
    case Deploy;
    case ReadLogs;
}

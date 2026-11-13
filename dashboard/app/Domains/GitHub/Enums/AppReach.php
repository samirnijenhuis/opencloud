<?php

namespace App\Domains\GitHub\Enums;

/** Whether the GitHub App of this install can reach one repository. */
enum AppReach: string
{
    case INSTALLED = 'installed';
    case NOT_INSTALLED = 'not_installed';
    case OTHER_ACCOUNT = 'other_account';
    case NO_APP = 'no_app';
    case NOT_GITHUB = 'not_github';
    case UNKNOWN = 'unknown';
}

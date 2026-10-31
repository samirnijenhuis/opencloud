<?php

namespace App\Domains\Builds\Enums;

/** The way that Git::withRemote() reads a repository. */
enum ReadPath: string
{
    case DEPLOY_KEY = 'deploy_key';
    case GITHUB_APP = 'github_app';
    case PLAIN_URL = 'plain_url';
}

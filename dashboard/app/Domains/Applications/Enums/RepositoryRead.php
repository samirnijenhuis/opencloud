<?php

namespace App\Domains\Applications\Enums;

/** How opencloud reads a repository right now, or why it cannot. */
enum RepositoryRead: string
{
    case GITHUB_APP = 'github_app';
    case DEPLOY_KEY = 'deploy_key';
    case PUBLIC = 'public';
    case BRANCH_MISSING = 'branch_missing';
    case KEY_REFUSED = 'key_refused';
    case UNREADABLE = 'unreadable';

    public function works(): bool
    {
        return in_array($this, [self::GITHUB_APP, self::DEPLOY_KEY, self::PUBLIC], true);
    }
}

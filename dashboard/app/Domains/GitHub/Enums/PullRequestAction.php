<?php

namespace App\Domains\GitHub\Enums;

/** The pull request actions that change a preview. GitHub sends others, for example "labeled", which opencloud ignores. */
enum PullRequestAction: string
{
    case OPENED = 'opened';
    case REOPENED = 'reopened';
    case SYNCHRONIZE = 'synchronize';
    case CLOSED = 'closed';

    /** Closed removes the preview. Each other action makes sure the preview runs the head commit. */
    public function closes(): bool
    {
        return $this === self::CLOSED;
    }
}

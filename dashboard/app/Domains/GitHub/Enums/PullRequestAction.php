<?php

namespace App\Domains\GitHub\Enums;

/** The pull request actions that change a preview. GitHub sends others, for example "edited", which opencloud ignores. */
enum PullRequestAction: string
{
    case OPENED = 'opened';
    case REOPENED = 'reopened';
    case SYNCHRONIZE = 'synchronize';
    case LABELED = 'labeled';
    case UNLABELED = 'unlabeled';
    case CLOSED = 'closed';

    /** Closed removes the preview. Each other action makes sure the preview runs the head commit. */
    public function closes(): bool
    {
        return $this === self::CLOSED;
    }

    public function changesLabels(): bool
    {
        return $this === self::LABELED || $this === self::UNLABELED;
    }
}

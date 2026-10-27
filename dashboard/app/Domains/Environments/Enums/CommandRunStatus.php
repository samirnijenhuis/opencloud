<?php

namespace App\Domains\Environments\Enums;

enum CommandRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';

    public function canMoveTo(self $next): bool
    {
        $allowed = match ($this) {
            self::QUEUED => [self::RUNNING, self::FAILED],
            self::RUNNING => [self::SUCCEEDED, self::FAILED, self::TIMED_OUT],
            self::SUCCEEDED, self::FAILED, self::TIMED_OUT => [],
        };

        return in_array($next, $allowed, true);
    }

    public function finished(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::TIMED_OUT], true);
    }
}

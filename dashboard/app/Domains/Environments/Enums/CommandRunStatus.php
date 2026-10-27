<?php

namespace App\Domains\Environments\Enums;

enum CommandRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    public function canMoveTo(self $next): bool
    {
        $allowed = match ($this) {
            self::Queued => [self::Running, self::Failed],
            self::Running => [self::Succeeded, self::Failed, self::TimedOut],
            self::Succeeded, self::Failed, self::TimedOut => [],
        };

        return in_array($next, $allowed, true);
    }

    public function finished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::TimedOut], true);
    }
}

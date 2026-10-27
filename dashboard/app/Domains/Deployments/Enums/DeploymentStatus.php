<?php

namespace App\Domains\Deployments\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Deploying = 'deploying';
    case Live = 'live';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function canMoveTo(self $next): bool
    {
        $allowed = match ($this) {
            self::Queued => [self::Building, self::Failed],
            self::Building => [self::Deploying, self::Failed],
            self::Deploying => [self::Live, self::Failed],
            self::Live => [self::Superseded],
            self::Failed, self::Superseded => [],
        };

        return in_array($next, $allowed, true);
    }
}

<?php

namespace App\Domains\Deployments\Enums;

enum DeploymentStatus: string
{
    case QUEUED = 'queued';
    case BUILDING = 'building';
    case DEPLOYING = 'deploying';
    case LIVE = 'live';
    case FAILED = 'failed';
    case SUPERSEDED = 'superseded';

    public function canMoveTo(self $next): bool
    {
        $allowed = match ($this) {
            self::QUEUED => [self::BUILDING, self::FAILED],
            self::BUILDING => [self::DEPLOYING, self::FAILED],
            self::DEPLOYING => [self::LIVE, self::FAILED],
            self::LIVE => [self::SUPERSEDED],
            self::FAILED, self::SUPERSEDED => [],
        };

        return in_array($next, $allowed, true);
    }
}

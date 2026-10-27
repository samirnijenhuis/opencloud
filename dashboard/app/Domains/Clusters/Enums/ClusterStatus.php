<?php

namespace App\Domains\Clusters\Enums;

/**
 * The state of a cluster. A created cluster starts in provisioning while OpenTofu creates it, then installs its parts
 * like a connected one. A failed cluster keeps its error, and "Reinstall parts" runs the install again.
 */
enum ClusterStatus: string
{
    case PROVISIONING = 'provisioning';
    case PENDING = 'pending';
    case INSTALLING = 'installing';
    case READY = 'ready';
    case FAILED = 'failed';
    case DESTROYING = 'destroying';

    /** OpenTofu or the install runs, so the page polls and the actions wait. */
    public function isBusy(): bool
    {
        return in_array($this, [self::PROVISIONING, self::INSTALLING, self::DESTROYING], true);
    }
}

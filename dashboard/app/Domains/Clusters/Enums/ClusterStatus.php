<?php

namespace App\Domains\Clusters\Enums;

/**
 * The state of a cluster. A created cluster starts in provisioning while OpenTofu creates it, then installs its parts
 * like a connected one. A failed cluster keeps its error, and "Reinstall parts" runs the install again.
 */
enum ClusterStatus: string
{
    case Provisioning = 'provisioning';
    case Pending = 'pending';
    case Installing = 'installing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Destroying = 'destroying';

    /** OpenTofu or the install runs, so the page polls and the actions wait. */
    public function isBusy(): bool
    {
        return in_array($this, [self::Provisioning, self::Installing, self::Destroying], true);
    }
}

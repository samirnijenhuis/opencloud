<?php

namespace App\Actions;

use App\Environments\EnvironmentColor;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\User;

class SetEnvironmentColor
{
    public function handle(Environment $environment, User $by, EnvironmentColor $color): Environment
    {
        $from = $environment->color;

        if ($from === $color) {
            return $environment;
        }

        $environment->update(['color' => $color]);

        AuditEvent::record($by, 'environment.updated', $environment, ['changes' => ['color' => ['from' => $from->value, 'to' => $color->value]]]);

        return $environment;
    }
}

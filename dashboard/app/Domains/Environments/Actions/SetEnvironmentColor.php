<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvironmentColor;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

class SetEnvironmentColor
{
    public function execute(Environment $environment, User $by, EnvironmentColor $color): Environment
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

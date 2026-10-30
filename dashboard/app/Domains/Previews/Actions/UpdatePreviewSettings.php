<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;

class UpdatePreviewSettings
{
    /**
     * Stores only the settings in the array. The settings apply to the next pull request event.
     * Previews that run keep their domain until their pull request closes.
     *
     * @param  array{previews_enabled?: bool, preview_domain?: ?string, preview_labels?: list<string>}  $settings
     */
    public function execute(Application $application, User $by, array $settings): void
    {
        $application->fill($settings);

        $changes = [];

        foreach ($application->getDirty() as $field => $to) {
            $changes[$field] = ['from' => $application->getOriginal($field), 'to' => $application->getAttribute($field)];
        }

        if ($changes === []) {
            return;
        }

        $application->save();

        AuditEvent::record($by, 'application.previews_updated', $application, ['changes' => $changes]);
    }
}

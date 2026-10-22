<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\User;

class UpdatePreviewSettings
{
    /**
     * The settings apply to the next pull request event. Previews that run keep their domain until their pull request closes.
     *
     * @param  array{previews_enabled: bool, allow_forks: bool, preview_domain: ?string}  $settings
     */
    public function handle(Application $application, User $by, array $settings): void
    {
        $application->update($settings);

        AuditEvent::record($by, 'application.previews_updated', $application, $settings);
    }
}

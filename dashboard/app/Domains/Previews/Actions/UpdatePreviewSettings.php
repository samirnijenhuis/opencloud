<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;

class UpdatePreviewSettings
{
    /**
     * The settings apply to the next pull request event. Previews that run keep their domain until their pull request closes.
     *
     * @param  array{previews_enabled: bool, allow_forks: bool, preview_domain: ?string, preview_labels: list<string>}  $settings
     */
    public function execute(Application $application, User $by, array $settings): void
    {
        $application->update($settings);

        AuditEvent::record($by, 'application.previews_updated', $application, $settings);
    }
}

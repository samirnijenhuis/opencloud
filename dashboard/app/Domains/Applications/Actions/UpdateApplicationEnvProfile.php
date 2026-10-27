<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\EnvProfileKind;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Members\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateApplicationEnvProfile
{
    /**
     * Stores the env profile and writes the datastore env values of every attachment of every environment again.
     * The preset, the port, and the secrets stay, because the preset is chosen at creation only. The next deploy reads the new names.
     */
    public function execute(Application $application, User $by, EnvProfileKind $envProfile): Application
    {
        $from = $application->env_profile;

        if ($from === $envProfile) {
            return $application;
        }

        DB::transaction(function () use ($application, $by, $from, $envProfile) {
            $application->update(['env_profile' => $envProfile]);

            $attachments = DatastoreAttachment::query()->whereIn('environment_id', $application->environments()->select('id'))->orderBy('id')->get();

            foreach ($attachments as $attachment) {
                AttachmentEnv::write($attachment);
            }

            AuditEvent::record($by, 'application.env_profile_updated', $application, ['from' => $from->value, 'to' => $envProfile->value]);
        });

        return $application;
    }
}

<?php

namespace App\Actions;

use App\Datastores\AttachmentEnv;
use App\Enums\ApplicationType;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\User;
use App\Presets\EnvProfileKind;
use BackedEnum;
use Illuminate\Support\Facades\DB;

class UpdateApplicationPreset
{
    public function __construct(private SetEnvVar $setEnvVar) {}

    /**
     * Stores the preset and the env profile. The next deploy builds with the new preset and reads the new env values.
     * A new profile rewrites the datastore env values of every environment. A new preset gives each environment the
     * secrets that the preset generates and the environment lacks. The processes, the commands, and the health path
     * stay, because the user may have changed them. The port stays too, unless the new preset fixes its port.
     */
    public function handle(Application $application, User $by, ApplicationType $type, EnvProfileKind $envProfile, ?int $port = null): Application
    {
        $application->fill([
            'type' => $type,
            'env_profile' => $envProfile,
            'port' => $type->choosesPort() ? ($port ?? $application->port) : $type->defaultPort(),
        ]);

        $changes = [];

        foreach (['type', 'env_profile', 'port'] as $field) {
            if ($application->isDirty($field)) {
                $changes[$field] = ['from' => $this->plain($application->getOriginal($field)), 'to' => $this->plain($application->getAttribute($field))];
            }
        }

        if ($changes === []) {
            return $application;
        }

        DB::transaction(function () use ($application, $by, $type, $changes) {
            $application->save();

            if (isset($changes['env_profile'])) {
                $attachments = DatastoreAttachment::query()->whereIn('environment_id', $application->environments()->select('id'))->orderBy('id')->get();

                foreach ($attachments as $attachment) {
                    AttachmentEnv::write($attachment);
                }
            }

            // Each preview generates its own secrets when it opens, so the template keeps none.
            if (isset($changes['type'])) {
                foreach ($application->environments()->where('kind', '!=', EnvironmentKind::PreviewTemplate)->orderBy('id')->get() as $environment) {
                    $missing = array_diff_key($type->generatedSecrets(), array_flip($environment->envVars()->pluck('key')->all()));

                    foreach ($missing as $key => $value) {
                        $this->setEnvVar->handle($environment, $by, $key, $value, true, EnvVarAvailability::Runtime);
                    }
                }
            }

            AuditEvent::record($by, 'application.preset_updated', $application, ['changes' => $changes]);
        });

        return $application;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}

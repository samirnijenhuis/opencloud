<?php

namespace App\Actions;

use App\Datastores\DatastoreStatus;
use App\Datastores\Engines;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\AuditEvent;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AttachDatastore
{
    private const DATABASES = [DatastoreKind::Mysql, DatastoreKind::Postgres];

    /**
     * Finds or creates the standard datastore of that kind on the cluster, creates the attachment and its env values,
     * and dispatches the provisioning. Attaching a kind again returns the existing attachment and retries a failed one.
     *
     * @throws InvalidArgumentException when the environment already has the other kind of database
     */
    public function handle(Environment $environment, User $by, DatastoreKind $kind): DatastoreAttachment
    {
        $attachments = $environment->datastoreAttachments()->with('datastore')->get();
        $existing = $attachments->first(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind === $kind);

        if ($existing !== null) {
            if ($existing->status === DatastoreStatus::Failed) {
                ProvisionDatastoreAttachment::dispatch($existing)->afterCommit();
            }

            return $existing;
        }

        if (in_array($kind, self::DATABASES, true) && $attachments->contains(fn (DatastoreAttachment $attachment) => in_array($attachment->datastore->kind, self::DATABASES, true))) {
            throw new InvalidArgumentException('This environment already has a database. Detach it first.');
        }

        return DB::transaction(function () use ($environment, $by, $kind) {
            $engine = Engines::for($kind);
            $datastore = Datastore::firstOrCreate(
                ['cluster_id' => $environment->application->cluster_id, 'kind' => $kind, 'source' => DatastoreSource::Standard],
                ['version' => DatastoreManifests::VERSIONS[$kind->value], 'root_password' => $kind === DatastoreKind::Valkey ? null : Str::random(32)],
            );

            $attachment = $environment->datastoreAttachments()->create([
                'datastore_id' => $datastore->id,
                ...$engine->names($environment),
                'password' => Str::random(32),
            ]);

            foreach ($engine->env($attachment) as $key => [$value, $isSecret]) {
                $environment->envVars()->updateOrCreate(['key' => $key, 'source' => EnvVarSource::Datastore], [
                    'value' => $value,
                    'is_secret' => $isSecret,
                    'available_at' => EnvVarAvailability::Runtime,
                    'datastore_attachment_id' => $attachment->id,
                ]);
            }

            AuditEvent::record($by, 'datastore.attached', $attachment, ['environment_id' => $environment->id, 'kind' => $kind->value]);
            ProvisionDatastoreAttachment::dispatch($attachment)->afterCommit();

            return $attachment;
        });
    }
}

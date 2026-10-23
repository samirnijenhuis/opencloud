<?php

namespace App\Actions;

use App\Datastores\DatastoreStatus;
use App\Datastores\Engines;
use App\Datastores\ManagedDatabase;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\ProvisionManagedDatabase;
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
     * The managed source creates a DigitalOcean database for this environment instead, see attachManaged().
     *
     * @throws InvalidArgumentException when the environment already has the other kind of database
     */
    public function handle(Environment $environment, ?User $by, DatastoreKind $kind, DatastoreSource $source = DatastoreSource::Standard): DatastoreAttachment
    {
        $attachments = $environment->datastoreAttachments()->with('datastore')->get();
        $existing = $attachments->first(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind === $kind);

        if ($existing !== null) {
            if ($existing->status === DatastoreStatus::Failed) {
                $existing->datastore->source === DatastoreSource::Managed
                    ? ProvisionManagedDatabase::dispatch($existing)->afterCommit()
                    : ProvisionDatastoreAttachment::dispatch($existing)->afterCommit();
            }

            return $existing;
        }

        if (in_array($kind, self::DATABASES, true) && $attachments->contains(fn (DatastoreAttachment $attachment) => in_array($attachment->datastore->kind, self::DATABASES, true))) {
            throw new InvalidArgumentException('This environment already has a database. Detach it first.');
        }

        if ($source === DatastoreSource::Managed) {
            return $this->attachManaged($environment, $by, $kind);
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

    /**
     * Creates a managed datastore of its own for the environment. The env values come after the apply, because
     * DigitalOcean generates the password.
     *
     * @throws InvalidArgumentException for a kind or a cluster that DigitalOcean cannot serve
     */
    private function attachManaged(Environment $environment, ?User $by, DatastoreKind $kind): DatastoreAttachment
    {
        $cluster = $environment->application->cluster;

        if (! in_array($kind, ManagedDatabase::KINDS, true)) {
            throw new InvalidArgumentException('DigitalOcean manages MySQL and Postgres only.');
        }

        if (! ManagedDatabase::availableOn($cluster)) {
            throw new InvalidArgumentException('A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.');
        }

        return DB::transaction(function () use ($environment, $by, $kind, $cluster) {
            $datastore = Datastore::query()->create([
                'cluster_id' => $cluster->id,
                'kind' => $kind,
                'source' => DatastoreSource::Managed,
                'version' => ManagedDatabase::VERSIONS[$kind->value],
            ]);

            $attachment = $environment->datastoreAttachments()->create([
                'datastore_id' => $datastore->id,
                ...Engines::for($kind)->names($environment),
            ]);

            AuditEvent::record($by, 'datastore.attached', $attachment, ['environment_id' => $environment->id, 'kind' => $kind->value, 'source' => 'managed']);
            ProvisionManagedDatabase::dispatch($attachment)->afterCommit();

            return $attachment;
        });
    }
}

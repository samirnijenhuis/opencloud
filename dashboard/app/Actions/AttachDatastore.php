<?php

namespace App\Actions;

use App\Datastores\AttachmentEnv;
use App\Datastores\DatabaseNames;
use App\Datastores\DatastoreStatus;
use App\Datastores\Engines;
use App\Datastores\ManagedDatabase;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\ProvisionManagedDatabase;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\AuditEvent;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AttachDatastore
{
    /**
     * Finds or creates the standard datastore of that kind on the cluster, creates the attachment and its env values,
     * and dispatches the provisioning. The managed source creates a DigitalOcean database for this environment instead.
     *
     * A MySQL or Postgres database has a connection name. The first one of an environment is the default and is named
     * "main" without $name. A later one needs a name. Valkey and RustFS have no name, and attaching one of them again
     * returns the existing attachment. Attaching a failed database or datastore again retries it.
     *
     * @throws InvalidArgumentException when the name is not valid or taken, or DigitalOcean cannot serve the database
     */
    public function handle(Environment $environment, ?User $by, DatastoreKind $kind, DatastoreSource $source = DatastoreSource::Standard, ?string $name = null): DatastoreAttachment
    {
        if ($source === DatastoreSource::Managed) {
            $this->checkManaged($environment, $kind);
        }

        return DB::transaction(function () use ($environment, $by, $kind, $source, $name) {
            $attachments = $environment->datastoreAttachments()->with('datastore')->get();
            $name = $kind->isSql() ? $this->databaseName($attachments, $name) : null;
            $existing = $attachments->first(fn (DatastoreAttachment $attachment) => $kind->isSql()
                ? $attachment->name === $name
                : $attachment->datastore->kind === $kind);

            if ($existing !== null) {
                return $this->retry($existing, $kind);
            }

            $fields = [
                'name' => $name,
                'is_default' => $kind->isSql() && ! $attachments->contains('is_default', true),
                ...Engines::for($kind)->names($environment, $name),
            ];

            $attachment = $source === DatastoreSource::Managed
                ? $this->createManaged($environment, $kind, $fields)
                : $this->createStandard($environment, $kind, $fields);

            AuditEvent::record($by, 'datastore.attached', $attachment, array_filter([
                'environment_id' => $environment->id,
                'kind' => $kind->value,
                'name' => $name,
                'source' => $source === DatastoreSource::Managed ? 'managed' : null,
            ]));

            return $attachment;
        });
    }

    /** @param  Collection<int, DatastoreAttachment>  $attachments */
    private function databaseName(Collection $attachments, ?string $name): string
    {
        if ($name === null) {
            return $attachments->contains(fn (DatastoreAttachment $attachment) => $attachment->name !== null)
                ? throw new InvalidArgumentException('This environment already has a database. Give the new database a name.')
                : DatabaseNames::FIRST_NAME;
        }

        $error = DatabaseNames::nameError($name);

        return $error === null ? $name : throw new InvalidArgumentException($error);
    }

    /** Only a failed attachment of the same kind can be attached again. For a database, that means the same name too. */
    private function retry(DatastoreAttachment $existing, DatastoreKind $kind): DatastoreAttachment
    {
        if ($kind->isSql() && ($existing->datastore->kind !== $kind || $existing->status !== DatastoreStatus::Failed)) {
            throw new InvalidArgumentException("This environment already has a database with the name {$existing->name}.");
        }

        if ($existing->status === DatastoreStatus::Failed) {
            $existing->datastore->source === DatastoreSource::Managed
                ? ProvisionManagedDatabase::dispatch($existing)->afterCommit()
                : ProvisionDatastoreAttachment::dispatch($existing)->afterCommit();
        }

        return $existing;
    }

    /** @param  array<string, mixed>  $fields */
    private function createStandard(Environment $environment, DatastoreKind $kind, array $fields): DatastoreAttachment
    {
        $datastore = Datastore::firstOrCreate(
            ['cluster_id' => $environment->application->cluster_id, 'kind' => $kind, 'source' => DatastoreSource::Standard],
            ['version' => DatastoreManifests::VERSIONS[$kind->value], 'root_password' => $kind === DatastoreKind::Valkey ? null : Str::random(32)],
        );

        $attachment = $environment->datastoreAttachments()->create([
            ...$fields,
            ...($kind->isSql() ? DatabaseNames::server($datastore) : []),
            'datastore_id' => $datastore->id,
            'password' => Str::random(32),
        ]);

        AttachmentEnv::write($attachment);
        ProvisionDatastoreAttachment::dispatch($attachment)->afterCommit();

        return $attachment;
    }

    /**
     * A managed database has a datastore of its own. Its env values come after the apply, because DigitalOcean
     * generates the password and the host.
     *
     * @param  array<string, mixed>  $fields
     */
    private function createManaged(Environment $environment, DatastoreKind $kind, array $fields): DatastoreAttachment
    {
        $datastore = Datastore::query()->create([
            'cluster_id' => $environment->application->cluster_id,
            'kind' => $kind,
            'source' => DatastoreSource::Managed,
            'version' => ManagedDatabase::VERSIONS[$kind->value],
        ]);

        $attachment = $environment->datastoreAttachments()->create([...$fields, 'datastore_id' => $datastore->id]);
        ProvisionManagedDatabase::dispatch($attachment)->afterCommit();

        return $attachment;
    }

    /** @throws InvalidArgumentException for a kind or a cluster that DigitalOcean cannot serve */
    private function checkManaged(Environment $environment, DatastoreKind $kind): void
    {
        if (! in_array($kind, ManagedDatabase::KINDS, true)) {
            throw new InvalidArgumentException('DigitalOcean manages MySQL and Postgres only.');
        }

        if (! ManagedDatabase::availableOn($environment->application->cluster)) {
            throw new InvalidArgumentException('A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.');
        }
    }
}

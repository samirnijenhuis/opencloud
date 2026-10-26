<?php

namespace App\Actions;

use App\Datastores\AttachmentEnv;
use App\Datastores\Connection;
use App\Datastores\DatabaseNames;
use App\Datastores\DatastoreStatus;
use App\Datastores\Provisioners;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttachDatastore
{
    /**
     * Creates the attachment with the provisioner of the kind and source, then its env values and its audit event.
     *
     * A database has a connection name. The first one of an environment is the default and is named "main" without
     * $name. A later one needs a name. Another family has no name, and attaching it again returns the existing
     * attachment. Attaching a failed attachment again retries it with the provisioner of its own datastore. An attach
     * with a typed address never reuses an attachment, so the address that the user typed never goes unused.
     *
     * @param  Connection|null  $connection  what the user typed for the external source, null for the sources that
     *                                       make their own
     *
     * @throws InvalidArgumentException when the name is not valid or taken, or the source cannot serve the kind here
     */
    public function handle(Environment $environment, ?User $by, DatastoreKind $kind, DatastoreSource $source = DatastoreSource::Standard, ?string $name = null, ?Connection $connection = null): DatastoreAttachment
    {
        $provisioner = Provisioners::for($kind, $source);
        $refusal = $provisioner->refusalOn($environment->application->cluster);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        return DB::transaction(function () use ($environment, $by, $kind, $source, $name, $connection, $provisioner) {
            // An attachment whose datastore is being destroyed no longer holds its name or its family.
            $attachments = $environment->datastoreAttachments()->with('datastore')->get()
                ->reject(fn (DatastoreAttachment $attachment) => $attachment->datastore->status === DatastoreStatus::Destroying);
            $named = $kind->family()->isNamed();
            $name = $named ? $this->databaseName($attachments, $name) : null;
            $existing = $attachments->first(fn (DatastoreAttachment $attachment) => $named
                ? $attachment->name === $name
                : $attachment->datastore->kind->family() === $kind->family());

            if ($existing !== null) {
                return $this->retry($existing, $kind, typed: $connection !== null);
            }

            $attachment = $provisioner->attach($environment, $name, $named && ! $attachments->contains('is_default', true), $connection);
            AttachmentEnv::write($attachment);

            AuditEvent::record($by, 'datastore.attached', $attachment, array_filter([
                'environment_id' => $environment->id,
                'kind' => $kind->value,
                'name' => $name,
                'source' => $source === DatastoreSource::Standard ? null : $source->value,
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

    /**
     * Only a failed attachment of the same kind can be attached again. For a database, that means the same name too.
     * Another kind of the same family is refused, so a second key-value engine gets an error and not the first one.
     * A typed address is refused too, because a retry would provision the old attachment and drop the new address.
     */
    private function retry(DatastoreAttachment $existing, DatastoreKind $kind, bool $typed): DatastoreAttachment
    {
        $named = $kind->family()->isNamed();

        if ($typed || $existing->datastore->kind !== $kind || ($named && $existing->status !== DatastoreStatus::Failed)) {
            throw new InvalidArgumentException($named
                ? "This environment already has a database with the name {$existing->name}."
                : "This environment already has a {$existing->datastore->kind->value} datastore.");
        }

        if ($existing->status === DatastoreStatus::Failed) {
            Provisioners::of($existing->datastore)->retry($existing);
        }

        return $existing;
    }
}

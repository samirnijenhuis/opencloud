<?php

namespace App\Domains\Datastores\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Connection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttachDatastore
{
    /**
     * Creates the attachment with the provisioner of the kind and source, then its env values and its audit event.
     *
     * A database or a bucket has a name that is unique in its family. The first one of its family in an environment is
     * the default and is named "main" without $name. A later one needs a name. A key-value store has no name, and
     * attaching it again returns the existing attachment. Attaching a failed attachment again retries it with the
     * provisioner of its own datastore. An attach with a typed address never reuses an attachment, so the address that
     * the user typed never goes unused.
     *
     * @param  Connection|null  $connection  what the user typed for the external source, null for the sources that
     *                                       make their own
     * @param  bool  $public  anyone can read the files of the bucket
     *
     * @throws InvalidArgumentException when the name is not valid or taken, or the source cannot serve the kind here
     */
    public function execute(Environment $environment, ?User $by, DatastoreKind $kind, DatastoreSource $source = DatastoreSource::STANDARD, ?string $name = null, ?Connection $connection = null, bool $public = false): DatastoreAttachment
    {
        $provisioner = Provisioners::for($kind, $source);
        $cluster = $environment->application->cluster;
        $refusal = $provisioner->refusalOn($cluster) ?? ($public ? $provisioner->publicRefusal($cluster) : null);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        return DB::transaction(function () use ($environment, $by, $kind, $source, $name, $connection, $public, $provisioner) {
            $family = $kind->family();
            // An attachment whose datastore is being destroyed no longer holds its name or its family.
            $attachments = $environment->datastoreAttachments()->where('family', $family)->with('datastore')->get()
                ->reject(fn (DatastoreAttachment $attachment) => $attachment->datastore->status === DatastoreStatus::DESTROYING);
            $named = $family->isNamed();
            $name = $named ? $this->name($family, $attachments, $name) : null;
            $existing = $named ? $attachments->firstWhere('name', $name) : $attachments->first();

            if ($existing !== null) {
                return $this->retry($existing, $kind, typed: $connection !== null, public: $public);
            }

            $attachment = $provisioner->attach($environment, $name, $named && ! $attachments->contains('is_default', true), $connection, $public);
            AttachmentEnv::write($attachment);

            AuditEvent::record($by, 'datastore.attached', $attachment, array_filter([
                'environment_id' => $environment->id,
                'kind' => $kind->value,
                'name' => $name,
                'source' => $source === DatastoreSource::STANDARD ? null : $source->value,
                'public' => $public,
            ]));

            // The public address gets its route now, not at the next scheduled sync.
            if ($public) {
                SyncCaddy::dispatch($environment->application->cluster)->afterCommit();
            }

            return $attachment;
        });
    }

    /** @param  Collection<int, DatastoreAttachment>  $attachments  the attachments of the family in the environment */
    private function name(ResourceFamily $family, Collection $attachments, ?string $name): string
    {
        if ($name === null) {
            return $attachments->contains(fn (DatastoreAttachment $attachment) => $attachment->name !== null)
                ? throw new InvalidArgumentException("This environment already has a {$family->value}. Give the new {$family->value} a name.")
                : ResourceFamily::FIRST_NAME;
        }

        $error = $family->nameError($name);

        return $error === null ? $name : throw new InvalidArgumentException($error);
    }

    /**
     * Only a failed attachment of the same kind can be attached again. For a database or a bucket, that means the
     * same name too. Another kind of the same family is refused, so a second key-value engine gets an error and not
     * the first one. A typed address is refused too, because a retry would provision the old attachment and drop the
     * new address. Another visibility is refused, because a retry would provision the old visibility.
     */
    private function retry(DatastoreAttachment $existing, DatastoreKind $kind, bool $typed, bool $public): DatastoreAttachment
    {
        $family = $kind->family();
        $named = $family->isNamed();

        if ($typed || $existing->datastore->kind !== $kind || ($named && $existing->status !== DatastoreStatus::FAILED)) {
            throw new InvalidArgumentException($named
                ? "This environment already has a {$family->value} with the name {$existing->name}."
                : "This environment already has a {$existing->datastore->kind->value} datastore.");
        }

        if ($existing->is_public !== $public) {
            throw new InvalidArgumentException("The bucket {$existing->name} is ".($existing->is_public ? 'public' : 'private').'. Change its visibility on its sheet.');
        }

        if ($existing->status === DatastoreStatus::FAILED) {
            Provisioners::of($existing->datastore)->retry($existing);
        }

        return $existing;
    }
}

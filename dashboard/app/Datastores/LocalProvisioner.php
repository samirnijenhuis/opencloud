<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\ResourceFamily;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\RevokeDatastoreAccess;
use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\User;
use App\Support\SecretMasker;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The local source: opencloud runs the datastore in the cluster of the app and talks to it with kubectl. A subclass
 * per kind holds what differs: the server manifests, the names, and the commands that create and remove the access
 * of one attachment. This class holds the queue, the status, and the server rollout that the kinds share.
 *
 * A database or bucket row is one shared server per cluster and kind. A key-value row only groups attachments,
 * because each attachment runs its own Valkey server.
 */
abstract class LocalProvisioner implements Provisioner
{
    private const SERVER_ROLLOUT_SECONDS = 600;

    abstract protected function kind(): DatastoreKind;

    /** @return list<array<string, mixed>> the StatefulSet server and its parts, or [] when each attachment runs its own server */
    abstract protected function server(Datastore $datastore): array;

    /**
     * @param  string|null  $name  the connection name of a MySQL or Postgres database, null for the other kinds
     * @return array{database_name: ?string, username: ?string}
     */
    abstract protected function names(Environment $environment, ?string $name): array;

    /** Creates the database, user, bucket, or server of one attachment. A second run converges to the same state. */
    abstract protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void;

    /** Removes the user, key, or server of one attachment. With $destroyData it also removes the data. */
    abstract protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void;

    /** One shared server with one database or bucket and user per attachment. Valkey overrides this. */
    protected function sharedServer(): bool
    {
        return true;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities($this->noDatabase());
    }

    public function refusalOn(Cluster $cluster): ?string
    {
        return null;
    }

    public function attach(Environment $environment, ?string $name, bool $isDefault, ?Connection $given = null): DatastoreAttachment
    {
        if ($given !== null) {
            throw new LogicException('The local source makes its own connection.');
        }

        $datastore = Datastore::firstOrCreate(
            ['cluster_id' => $environment->application->cluster_id, 'kind' => $this->kind(), 'source' => DatastoreSource::Standard],
            ['version' => DatastoreManifests::VERSIONS[$this->kind()->value], 'root_password' => $this->sharedServer() ? Str::random(32) : null],
        );

        $attachment = $environment->datastoreAttachments()->create([
            'name' => $name,
            'is_default' => $isDefault,
            ...$this->names($environment, $name),
            ...($this->kind()->family() === ResourceFamily::Database ? DatabaseNames::server($datastore) : []),
            'datastore_id' => $datastore->id,
            'password' => Str::random(32),
        ]);

        ProvisionDatastoreAttachment::dispatch($attachment)->afterCommit();

        return $attachment;
    }

    public function retry(DatastoreAttachment $attachment): void
    {
        ProvisionDatastoreAttachment::dispatch($attachment)->afterCommit();
    }

    public function detach(DatastoreAttachment $attachment, ?User $by, bool $destroyData): void
    {
        $datastore = $attachment->datastore;
        $environment = $attachment->environment;

        DB::transaction(function () use ($attachment, $by, $destroyData, $datastore, $environment) {
            // The env values of the attachment go with it through the foreign key.
            $attachment->delete();

            AuditEvent::record($by, $destroyData ? 'datastore.destroyed' : 'datastore.detached', $environment, [
                'datastore_id' => $datastore->id,
                'kind' => $datastore->kind->value,
            ]);

            RevokeDatastoreAccess::dispatch($datastore, Arr::except($attachment->getAttributes(), ['password']), $destroyData)->afterCommit();
        });
    }

    /** Each step converges, so a failed attachment can simply run again. */
    public function provision(DatastoreAttachment $attachment): void
    {
        $datastore = $attachment->datastore;
        $kubectl = Kubectl::for($datastore->cluster);
        $masker = new SecretMasker(array_filter([$attachment->password, $datastore->root_password]));

        $attachment->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);

        try {
            $this->provisionServer($kubectl, $datastore, $masker);
            $this->grant($kubectl, $attachment);
        } catch (Throwable $e) {
            $attachment->update(['status' => DatastoreStatus::Failed, 'error' => $masker->mask($e->getMessage())]);

            return;
        }

        $attachment->update(['status' => DatastoreStatus::Ready]);
    }

    public function release(Datastore $datastore, array $attachment, bool $destroyData): void
    {
        $released = (new DatastoreAttachment)->setRawAttributes($attachment)->setRelation('datastore', $datastore);

        $this->revoke(Kubectl::for($datastore->cluster), $released, $destroyData);
    }

    protected function noDatabase(): string
    {
        return "A {$this->kind()->value} datastore has no database to back up.";
    }

    /** The apply and the rollout check run each time, so a server that someone removed comes back. */
    private function provisionServer(Kubectl $kubectl, Datastore $datastore, SecretMasker $masker): void
    {
        if ($datastore->status !== DatastoreStatus::Ready) {
            $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        }

        try {
            $server = $this->server($datastore);
            $kubectl->apply([DatastoreManifests::namespace(), ...$server]);

            if ($server !== []) {
                $kubectl->rolloutStatus(DatastoreManifests::NAMESPACE, 'statefulset', DatastoreManifests::serverName($datastore), self::SERVER_ROLLOUT_SECONDS)->throw();
            }
        } catch (Throwable $e) {
            $datastore->update(['status' => DatastoreStatus::Failed, 'error' => $masker->mask($e->getMessage())]);

            throw $e;
        }

        if ($datastore->status !== DatastoreStatus::Ready) {
            $datastore->update(['status' => DatastoreStatus::Ready]);
        }
    }
}

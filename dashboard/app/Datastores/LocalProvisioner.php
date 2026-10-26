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
 * The local source: opencloud runs the datastore in the cluster of the app, and a kubectl engine does the work. This
 * class is the only holder of an engine.
 *
 * A database or bucket row is one shared server per cluster and kind. A key-value row only groups attachments,
 * because each attachment runs its own Valkey server.
 */
final class LocalProvisioner implements Provisioner
{
    private const SERVER_ROLLOUT_SECONDS = 600;

    private function __construct(
        private DatastoreKind $kind,
        private Engine $engine,
        private bool $sharedServer,
        private Capabilities $capabilities,
    ) {}

    /** One shared server with one database and user per attachment. opencloud dumps it. */
    public static function database(DatastoreKind $kind, SqlEngine $engine): self
    {
        return new self($kind, $engine, sharedServer: true, capabilities: new Capabilities(new InClusterDumps($engine)));
    }

    /** One server per attachment, so the datastore row has no root password and no server of its own. */
    public static function keyValue(DatastoreKind $kind, Engine $engine): self
    {
        return new self($kind, $engine, sharedServer: false, capabilities: new Capabilities(self::noDatabase($kind)));
    }

    /** One shared server with one bucket and user per attachment. The nightly job copies its buckets. */
    public static function bucket(DatastoreKind $kind, Engine $engine): self
    {
        return new self($kind, $engine, sharedServer: true, capabilities: new Capabilities(self::noDatabase($kind), copiesBuckets: true));
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
            ['cluster_id' => $environment->application->cluster_id, 'kind' => $this->kind, 'source' => DatastoreSource::Standard],
            ['version' => DatastoreManifests::VERSIONS[$this->kind->value], 'root_password' => $this->sharedServer ? Str::random(32) : null],
        );

        $attachment = $environment->datastoreAttachments()->create([
            'name' => $name,
            'is_default' => $isDefault,
            ...$this->engine->names($environment, $name),
            ...($this->kind->family() === ResourceFamily::Database ? DatabaseNames::server($datastore) : []),
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
            $this->engine->attach($kubectl, $attachment);
        } catch (Throwable $e) {
            $attachment->update(['status' => DatastoreStatus::Failed, 'error' => $masker->mask($e->getMessage())]);

            return;
        }

        $attachment->update(['status' => DatastoreStatus::Ready]);
    }

    public function release(Datastore $datastore, array $attachment, bool $destroyData): void
    {
        $released = (new DatastoreAttachment)->setRawAttributes($attachment)->setRelation('datastore', $datastore);

        $this->engine->detach(Kubectl::for($datastore->cluster), $released, $destroyData);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return $this->engine->connection($attachment);
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    private static function noDatabase(DatastoreKind $kind): string
    {
        return "A {$kind->value} datastore has no database to back up.";
    }

    /** The apply and the rollout check run each time, so a server that someone removed comes back. */
    private function provisionServer(Kubectl $kubectl, Datastore $datastore, SecretMasker $masker): void
    {
        if ($datastore->status !== DatastoreStatus::Ready) {
            $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        }

        try {
            $server = $this->engine->server($datastore);
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

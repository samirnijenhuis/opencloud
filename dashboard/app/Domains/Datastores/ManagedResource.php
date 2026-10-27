<?php

namespace App\Domains\Datastores;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionManagedDatabase;
use App\Domains\Datastores\Jobs\TearDownManagedDatabase;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * The managed source: DigitalOcean runs the resource and OpenTofu creates it, one provider resource per attachment.
 * So each attachment has its own managed datastore record. The stack knows the modules. This class runs the same
 * lifecycle for each kind, on the builds queue with the lock tofu-database-{id}.
 */
final class ManagedResource implements Provisioner
{
    /**
     * @param  string  $right  the right of the DigitalOcean key that the stack needs, from the guide in Providers
     * @param  string  $refusal  why an environment on another cluster, or without that right, cannot get it
     */
    public function __construct(
        private DatastoreKind $kind,
        private ManagedStack $stack,
        private string $right,
        private string $refusal,
        private Capabilities $capabilities,
    ) {}

    /** Only a cluster that opencloud created on DigitalOcean has the VPC and the id that the firewall rule needs. */
    public function refusalOn(Cluster $cluster): ?string
    {
        if ($cluster->kind !== ClusterKind::Created || $cluster->provider !== 'digitalocean' || $cluster->provider_cluster_id === null) {
            return $this->refusal;
        }

        $credential = ProviderCredential::query()->where('provider', 'digitalocean')->first();

        return $credential !== null && ! in_array($this->right, $credential->missing_rights ?? [], true) ? null : $this->refusal;
    }

    /** DigitalOcean generates the address and the secrets, so the attachment has none until the apply. */
    public function attach(Environment $environment, ?string $name, bool $isDefault, ?Connection $given = null): DatastoreAttachment
    {
        if ($given !== null) {
            throw new LogicException('The managed source makes its own connection.');
        }

        $datastore = Datastore::query()->create([
            'cluster_id' => $environment->application->cluster_id,
            'kind' => $this->kind,
            'source' => DatastoreSource::Managed,
            'version' => $this->stack->version(),
        ]);

        $attachment = $environment->datastoreAttachments()->create([
            'name' => $name,
            'is_default' => $isDefault,
            ...$this->stack->names($environment, $name),
            'datastore_id' => $datastore->id,
        ]);

        ProvisionManagedDatabase::dispatch($attachment)->afterCommit();

        return $attachment;
    }

    public function retry(DatastoreAttachment $attachment): void
    {
        ProvisionManagedDatabase::dispatch($attachment)->afterCommit();
    }

    /**
     * The resource belongs to this environment only, so a detach always destroys it and its data on DigitalOcean.
     * The env values, the name, and the default go now, so a new database can take the name. The records stay until
     * the destroy succeeds, so a failed destroy shows its error.
     */
    public function detach(DatastoreAttachment $attachment, ?User $by, bool $destroyData): void
    {
        $datastore = $attachment->datastore;

        DB::transaction(function () use ($attachment, $by, $datastore) {
            $attachment->envVars()->delete();
            $attachment->update(['name' => null, 'is_default' => false]);
            $datastore->update(['status' => DatastoreStatus::Destroying, 'error' => null]);

            AuditEvent::record($by, 'datastore.destroy_requested', $attachment->environment, [
                'datastore_id' => $datastore->id,
                'kind' => $datastore->kind->value,
                'source' => 'managed',
            ]);

            TearDownManagedDatabase::dispatch($datastore, Arr::except($attachment->getAttributes(), ['password']))->afterCommit();
        });
    }

    /** Writes the env values after the apply, because only then is the address known. */
    public function provision(DatastoreAttachment $attachment): void
    {
        $datastore = $attachment->datastore;

        if ($datastore->status === DatastoreStatus::Destroying) {
            return;
        }

        $attachment->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null, 'tofu_log' => null]);

        try {
            $address = $this->stack->apply($attachment);
        } catch (Throwable $e) {
            self::failed($attachment, $e);

            return;
        }

        DB::transaction(function () use ($attachment, $datastore, $address) {
            // The default can change during the apply, so the env form comes from the row as it is now.
            $attachment->refresh();
            $attachment->update($address);
            AttachmentEnv::write($attachment);

            $datastore->update(['status' => DatastoreStatus::Ready]);
            $attachment->update(['status' => DatastoreStatus::Ready]);
        });
    }

    /** Runs tofu destroy, then removes the attachment and the datastore. A failed destroy keeps both with the error. */
    public function release(Datastore $datastore, array $attachment, bool $destroyData): void
    {
        $released = (new DatastoreAttachment)->setRawAttributes($attachment)->setRelation('datastore', $datastore);
        $datastore->update(['status' => DatastoreStatus::Destroying, 'error' => null, 'tofu_log' => null]);

        try {
            $this->stack->destroy($released);
        } catch (Throwable $e) {
            self::destroyFailed($datastore, $e);

            return;
        }

        DB::transaction(function () use ($datastore) {
            AuditEvent::record(null, 'datastore.destroyed', $datastore, ['kind' => $datastore->kind->value, 'source' => 'managed']);
            $datastore->attachments()->delete();
            $datastore->delete();
        });
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return $this->stack->connection($attachment);
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    /** provision() calls it for an OpenTofu error, and the job calls it when the queue stops the apply. */
    public static function failed(DatastoreAttachment $attachment, ?Throwable $e): void
    {
        $error = $e?->getMessage() ?? 'The OpenTofu run failed.';

        $attachment->update(['status' => DatastoreStatus::Failed, 'error' => $error]);
        $attachment->datastore->update(['status' => DatastoreStatus::Failed, 'error' => $error]);
    }

    /** release() calls it for an OpenTofu error, and the job calls it when the queue stops the destroy. */
    public static function destroyFailed(Datastore $datastore, ?Throwable $e): void
    {
        $datastore->update(['status' => DatastoreStatus::Failed, 'error' => 'The destroy failed. '.($e?->getMessage() ?? '')]);
    }
}

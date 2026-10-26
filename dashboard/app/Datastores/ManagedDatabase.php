<?php

namespace App\Datastores;

use App\Clusters\DigitalOceanCluster;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Jobs\ProvisionManagedDatabase;
use App\Jobs\TearDownManagedDatabase;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Tofu\Runner;
use App\Tofu\Stack;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * A DigitalOcean managed MySQL or Postgres for one environment. tofu/digitalocean/database creates one database
 * cluster per attachment, so each attachment has its own managed datastore record. OpenTofu runs on the builds queue
 * with the lock tofu-database-{id}.
 */
final class ManagedDatabase implements Provisioner
{
    public const PRICING_URL = 'https://www.digitalocean.com/pricing/managed-databases';

    /** The refusal for a managed kind that Provisioners has no arm for. */
    public const KINDS_ONLY = 'DigitalOcean manages MySQL and Postgres only.';

    /**
     * DigitalOcean MySQL needs TLS with DigitalOcean's own CA, and a stock Laravel app does not load that CA yet.
     * Postgres connects with sslmode=prefer, so the dashboard offers only Postgres until the CA is handled.
     */
    public const MYSQL_NOT_OFFERED = 'A managed database on DigitalOcean is Postgres only for now. Use managed Postgres, or MySQL in the cluster.';

    /** @var array<string, string> kind => the major version on DigitalOcean */
    private const VERSIONS = ['mysql' => '8', 'postgres' => '17'];

    private const ENGINES = ['mysql' => 'mysql', 'postgres' => 'pg'];

    public function __construct(
        private DatastoreKind $kind,
        private ?string $notOffered = null,
    ) {}

    /**
     * Only a cluster that opencloud created on DigitalOcean has the VPC and the id that the firewall rule needs.
     * A key without the database right cannot create the database.
     */
    public function refusalOn(Cluster $cluster): ?string
    {
        $refusal = 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.';

        if ($cluster->kind !== ClusterKind::Created || $cluster->provider !== 'digitalocean' || $cluster->provider_cluster_id === null) {
            return $refusal;
        }

        $credential = ProviderCredential::query()->where('provider', 'digitalocean')->first();

        return $credential !== null && ! in_array('database', $credential->missing_rights ?? [], true) ? null : $refusal;
    }

    /** DigitalOcean generates the host and the password, so the attachment has neither until the apply. */
    public function attach(Environment $environment, ?string $name, bool $isDefault, ?Connection $given = null): DatastoreAttachment
    {
        if ($given !== null) {
            throw new LogicException('The managed source makes its own connection.');
        }

        $datastore = Datastore::query()->create([
            'cluster_id' => $environment->application->cluster_id,
            'kind' => $this->kind,
            'source' => DatastoreSource::Managed,
            'version' => self::VERSIONS[$this->kind->value],
        ]);

        $attachment = $environment->datastoreAttachments()->create([
            'name' => $name,
            'is_default' => $isDefault,
            ...DatabaseNames::names($environment, $name ?? DatabaseNames::FIRST_NAME),
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
     * The database belongs to this environment only, so a detach always destroys it and its data on DigitalOcean.
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

    /** Writes the env values after the apply, because only then are the host and the password known. */
    public function provision(DatastoreAttachment $attachment): void
    {
        $datastore = $attachment->datastore;

        if ($datastore->status === DatastoreStatus::Destroying) {
            return;
        }

        $attachment->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null, 'tofu_log' => null]);

        try {
            $outputs = app(Runner::class)->apply($this->stack($attachment), $datastore);
        } catch (Throwable $e) {
            self::failed($attachment, $e);

            return;
        }

        DB::transaction(function () use ($attachment, $datastore, $outputs) {
            // The default can change during the apply, so the env form comes from the row as it is now.
            $attachment->refresh();
            $attachment->update(['host' => (string) $outputs['host'], 'port' => (int) $outputs['port'], 'password' => (string) $outputs['password']]);
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
            app(Runner::class)->destroy($this->stack($released), $datastore);
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
        return SqlConnection::of($attachment);
    }

    /** DigitalOcean backs the database up itself, and the nightly dump cannot reach it. */
    public function capabilities(): Capabilities
    {
        return new Capabilities('DigitalOcean backs up a managed database itself.', notOffered: $this->notOffered);
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

    private function stack(DatastoreAttachment $attachment): Stack
    {
        $datastore = $attachment->datastore;
        $cluster = $datastore->cluster;

        return new Stack('digitalocean/database', "database-{$datastore->id}", [
            'name' => "{$cluster->name}-{$datastore->kind->value}-{$datastore->id}",
            'engine' => self::ENGINES[$datastore->kind->value],
            'engine_version' => $datastore->version,
            'size' => config('opencloud.managed_database_size'),
            'region' => $cluster->region,
            'vpc_id' => $cluster->provider_vpc_id,
            'kubernetes_cluster_id' => $cluster->provider_cluster_id,
            'database' => $attachment->database_name,
            'user' => $attachment->username,
        ], ['DIGITALOCEAN_TOKEN' => DigitalOceanCluster::token()]);
    }
}

<?php

namespace App\Domains\Datastores;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The external source: a server that the user runs. Opencloud stores the address that the user typed and writes the
 * env values. It runs no job, no kubectl, and no OpenTofu, and it never touches the data on that server.
 *
 * Each attachment has its own datastore row without a cluster, because the server belongs to no cluster. The address
 * lives on the attachment, in the columns that SqlConnection::of(), KeyValueConnection::of(), and
 * BucketConnection::of() read.
 */
final class ExternalProvisioner implements Provisioner
{
    public function __construct(
        private DatastoreKind $kind,
        private string $label,
    ) {}

    public function refusalOn(Cluster $cluster): ?string
    {
        return null;
    }

    /** Only the address of $given counts. The action decides the name and the default of a database or a bucket. */
    public function attach(Environment $environment, ?string $name, bool $isDefault, ?Connection $given = null): DatastoreAttachment
    {
        $address = $this->address($given);

        $datastore = Datastore::query()->create([
            'cluster_id' => null,
            'kind' => $this->kind,
            'source' => DatastoreSource::EXTERNAL,
            'version' => '',
            'status' => DatastoreStatus::READY,
        ]);

        return $environment->datastoreAttachments()->create([
            'family' => $this->kind->family(),
            'name' => $name,
            'is_default' => $isDefault,
            ...$address,
            'datastore_id' => $datastore->id,
            'status' => DatastoreStatus::READY,
        ]);
    }

    /** The attachment is ready from the start, so there is nothing to try again. */
    public function retry(DatastoreAttachment $attachment): void {}

    /** The data belongs to the server of the user, so $destroyData changes nothing. Only the rows go. */
    public function detach(DatastoreAttachment $attachment, ?User $by, bool $destroyData): void
    {
        $datastore = $attachment->datastore;
        $environment = $attachment->environment;

        DB::transaction(function () use ($attachment, $by, $datastore, $environment) {
            // The env values of the attachment go with it through the foreign key.
            $attachment->delete();
            $datastore->delete();

            AuditEvent::record($by, 'datastore.detached', $environment, [
                'datastore_id' => $datastore->id,
                'kind' => $datastore->kind->value,
                'source' => DatastoreSource::EXTERNAL->value,
            ]);
        });
    }

    public function provision(DatastoreAttachment $attachment): void
    {
        $attachment->update(['status' => DatastoreStatus::READY, 'error' => null]);
    }

    public function release(Datastore $datastore, array $attachment, bool $destroyData): void {}

    public function scale(DatastoreAttachment $attachment, bool $awake): void {}

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return match ($this->kind->family()) {
            ResourceFamily::DATABASE => SqlConnection::of($attachment),
            ResourceFamily::KEY_VALUE => KeyValueConnection::of($attachment),
            ResourceFamily::BUCKET => BucketConnection::of($attachment),
        };
    }

    public function capabilities(): Capabilities
    {
        $noDumps = match ($this->kind->family()) {
            ResourceFamily::DATABASE => 'Opencloud does not back up a database that you run yourself.',
            ResourceFamily::KEY_VALUE => 'Opencloud does not back up a key-value store that you run yourself.',
            ResourceFamily::BUCKET => 'Opencloud does not copy a bucket that you run yourself.',
        };

        return new Capabilities($noDumps, label: $this->label);
    }

    /**
     * The attachment columns of the typed connection. A bucket keeps its endpoint in host, the bucket in
     * database_name, and the key in username and password.
     *
     * @return array<string, mixed>
     */
    private function address(?Connection $given): array
    {
        $family = $this->kind->family();

        return match (true) {
            $given instanceof SqlConnection && $given->kind === $this->kind => [
                'host' => $given->host,
                'port' => $given->port,
                'database_name' => $given->database,
                'username' => $given->username,
                'password' => $given->password,
            ],
            $given instanceof KeyValueConnection && $family === ResourceFamily::KEY_VALUE => [
                'host' => $given->host,
                'port' => $given->port,
                'password' => $given->password,
                'tls' => $given->tls,
            ],
            $given instanceof BucketConnection && $family === ResourceFamily::BUCKET => [
                'host' => $given->endpoint,
                'region' => $given->region,
                'database_name' => $given->bucket,
                'username' => $given->accessKeyId,
                'password' => $given->secretAccessKey,
                'path_style' => $given->pathStyle,
            ],
            default => throw new LogicException("The external source needs the {$this->kind->value} address that the user typed."),
        };
    }
}

<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;

/**
 * Valkey has no per-tenant isolation worth trusting, so each attachment runs its own server. The Valkey datastore
 * row only groups those servers per cluster.
 */
final class LocalValkey extends LocalProvisioner
{
    private const ROLLOUT_TIMEOUT_SECONDS = 300;

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::VALKEY;
    }

    protected function sharedServer(): bool
    {
        return false;
    }

    protected function server(Datastore $datastore): array
    {
        return [];
    }

    protected function names(Environment $environment, ?string $name): array
    {
        return ['database_name' => null, 'username' => null];
    }

    protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        $kubectl->apply(DatastoreManifests::valkey($attachment));
        $kubectl->rolloutStatus(DatastoreManifests::NAMESPACE, 'deployment', DatastoreManifests::valkeyName($attachment), self::ROLLOUT_TIMEOUT_SECONDS)->throw();
    }

    /** Without $destroyData the volume stays, like the database of a detached MySQL attachment. */
    protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        $name = DatastoreManifests::valkeyName($attachment);

        foreach (['deployment', 'service', 'secret', ...($destroyData ? ['persistentvolumeclaim'] : [])] as $kind) {
            $kubectl->delete($kind, $name, DatastoreManifests::NAMESPACE);
        }
    }

    /** Each attachment has its own server, so the server sleeps with its environment. The volume keeps the data. */
    public function scale(DatastoreAttachment $attachment, bool $awake): void
    {
        Kubectl::for($attachment->datastore->cluster)->run([
            'scale', 'deployment/'.DatastoreManifests::valkeyName($attachment), '--replicas='.($awake ? 1 : 0), '--namespace='.DatastoreManifests::NAMESPACE,
        ])->throw();
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return new KeyValueConnection(DatastoreManifests::host(DatastoreManifests::valkeyName($attachment)), 6379, $attachment->password, tls: false);
    }
}

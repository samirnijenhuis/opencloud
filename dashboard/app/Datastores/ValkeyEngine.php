<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * Valkey has no per-tenant isolation worth trusting, so each attachment runs its own server. The Valkey datastore
 * row only groups those servers per cluster.
 */
final class ValkeyEngine implements Engine
{
    private const ROLLOUT_TIMEOUT_SECONDS = 300;

    public function server(Datastore $datastore): array
    {
        return [];
    }

    public function names(Environment $environment, ?string $name): array
    {
        return ['database_name' => null, 'username' => null];
    }

    public function attach(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        $kubectl->apply(DatastoreManifests::valkey($attachment));
        $kubectl->rolloutStatus(DatastoreManifests::NAMESPACE, 'deployment', DatastoreManifests::valkeyName($attachment), self::ROLLOUT_TIMEOUT_SECONDS)->throw();
    }

    /** Without $destroyData the volume stays, like the database of a detached MySQL attachment. */
    public function detach(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        $name = DatastoreManifests::valkeyName($attachment);

        foreach (['deployment', 'service', 'secret', ...($destroyData ? ['persistentvolumeclaim'] : [])] as $kind) {
            $kubectl->delete($kind, $name, DatastoreManifests::NAMESPACE);
        }
    }

    public function env(DatastoreAttachment $attachment): array
    {
        return [
            'REDIS_CLIENT' => ['phpredis', false],
            'REDIS_HOST' => [DatastoreManifests::host(DatastoreManifests::valkeyName($attachment)), false],
            'REDIS_PORT' => ['6379', false],
            'REDIS_PASSWORD' => [$attachment->password, true],
            'CACHE_STORE' => ['redis', false],
        ];
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return new KeyValueConnection(DatastoreManifests::host(DatastoreManifests::valkeyName($attachment)), 6379, $attachment->password, tls: false);
    }
}

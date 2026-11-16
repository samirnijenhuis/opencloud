<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;
use App\Support\Kube\Labels;

/**
 * Each attachment runs its own Meilisearch server, like Valkey, so the indexes of two environments never mix and the
 * server sleeps with its environment. The Meilisearch datastore row only groups those servers per cluster.
 */
final class LocalMeilisearch extends LocalProvisioner
{
    private const ROLLOUT_TIMEOUT_SECONDS = 300;

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::MEILISEARCH;
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

    public function capabilities(): Capabilities
    {
        return new Capabilities('Opencloud does not back up a search index. The app fills it again from its database, for example with php artisan scout:import.');
    }

    protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        $kubectl->apply(DatastoreManifests::meilisearch($attachment));
        $kubectl->rolloutStatus(DatastoreManifests::NAMESPACE, 'deployment', DatastoreManifests::meilisearchName($attachment), self::ROLLOUT_TIMEOUT_SECONDS)->throw();
    }

    /** One call removes the objects by the label of the attachment. Without $destroyData the volume stays. */
    protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        $kinds = implode(',', ['deployment', 'service', 'secret', ...($destroyData ? ['persistentvolumeclaim'] : [])]);

        $kubectl->run([
            'delete', $kinds, '--selector='.Labels::ATTACHMENT."={$attachment->id}", '--namespace='.DatastoreManifests::NAMESPACE, '--ignore-not-found', '--wait=false',
        ])->throw();
    }

    /** The volume keeps the indexes while the server sleeps. */
    public function scale(DatastoreAttachment $attachment, bool $awake): void
    {
        Kubectl::for($attachment->datastore->cluster)->run([
            'scale', 'deployment/'.DatastoreManifests::meilisearchName($attachment), '--replicas='.($awake ? 1 : 0), '--namespace='.DatastoreManifests::NAMESPACE,
        ])->throw();
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return new SearchConnection('http://'.DatastoreManifests::host(DatastoreManifests::meilisearchName($attachment)).':7700', $attachment->password);
    }
}

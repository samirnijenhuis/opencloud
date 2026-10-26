<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * What one kind of standard datastore does. Engines::for() picks the engine of a kind.
 */
interface Engine
{
    /** @return list<array<string, mixed>> the StatefulSet server and its parts, or [] when each attachment runs its own server */
    public function server(Datastore $datastore): array;

    /**
     * @param  string|null  $name  the connection name of a MySQL or Postgres database, null for the other kinds
     * @return array{database_name: ?string, username: ?string}
     */
    public function names(Environment $environment, ?string $name): array;

    /** Creates the database, user, bucket, or server of one attachment. A second run converges to the same state. */
    public function attach(Kubectl $kubectl, DatastoreAttachment $attachment): void;

    /** Removes the user, key, or server of one attachment. With $destroyData it also removes the data. */
    public function detach(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void;

    /** How the app reaches the attachment, or null while that is not known yet. The engine writes no env names here. */
    public function connection(DatastoreAttachment $attachment): ?Connection;
}

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

    /** @return array{database_name: ?string, username: ?string} */
    public function names(Environment $environment): array;

    /** Creates the database, user, bucket, or server of one attachment. A second run converges to the same state. */
    public function attach(Kubectl $kubectl, DatastoreAttachment $attachment): void;

    /** Removes the user, key, or server of one attachment. With $destroyData it also removes the data. */
    public function detach(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void;

    /** @return array<string, array{0: string, 1: bool}> the Laravel env, key => [value, is secret] */
    public function env(DatastoreAttachment $attachment): array;
}

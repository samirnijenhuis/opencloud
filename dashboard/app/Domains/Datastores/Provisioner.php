<?php

namespace App\Domains\Datastores;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

/**
 * Everything opencloud does for one kind from one source: its storage shape, its provisioning, its release, its
 * connection, and its capabilities. Only Provisioners creates one, and it keys on kind and source together, so the
 * code for a managed datastore never reaches kubectl.
 *
 * Actions and jobs call one method per operation and do not test the source. The request-time methods run inside the
 * transaction of the action. provision() and release() run inside the queued job that this source dispatches, with the
 * lock and the queue of that job.
 */
interface Provisioner
{
    /** Why an environment on this cluster cannot get it, or null. */
    public function refusalOn(Cluster $cluster): ?string;

    /**
     * Finds or creates the datastore row in the storage shape of this source, creates the attachment row, and queues
     * provision() after the commit. The action writes the env and the audit event afterwards.
     *
     * @param  string|null  $name  the name of a database or a bucket, null for a key-value store
     * @param  Connection|null  $given  the connection that the user typed, for a source that provisions nothing (the
     *                                  external source, later). The local and managed sources make their own.
     */
    public function attach(Environment $environment, ?string $name, bool $isDefault, ?Connection $given = null): DatastoreAttachment;

    /** Queues provision() again for a failed attachment. */
    public function retry(DatastoreAttachment $attachment): void;

    /**
     * Removes the attachment and its env now, records the audit event, and queues release(). Without $destroyData the
     * data stays, so a later attach with the same name finds it again. A source whose data belongs to one attachment
     * destroys the data each time and keeps the rows until the destroy succeeds.
     */
    public function detach(DatastoreAttachment $attachment, ?User $by, bool $destroyData): void;

    /**
     * Creates what the attachment needs and marks it ready, or failed with the error. A second run converges.
     * Runs inside the queued job of this source, or synchronously in RestoreInstall.
     */
    public function provision(DatastoreAttachment $attachment): void;

    /**
     * Removes the access, the server, or the provider resource of an attachment that detach() let go.
     *
     * @param  array<string, mixed>  $attachment  the attributes of the attachment without the password; the row can be gone
     */
    public function release(Datastore $datastore, array $attachment, bool $destroyData): void;

    /**
     * Stops a server that runs for this attachment alone while its environment sleeps, and starts it again on the wake.
     * A shared server and a resource outside the cluster keep running.
     */
    public function scale(DatastoreAttachment $attachment, bool $awake): void;

    /** How the app reaches it, or null while that is not known yet (a managed database before its apply). */
    public function connection(DatastoreAttachment $attachment): ?Connection;

    /** One row of the capability table. */
    public function capabilities(): Capabilities;
}

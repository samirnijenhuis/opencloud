<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;

/**
 * A local database with one database per attachment, which the nightly backup dumps. The server pod runs the dump and
 * the SQL, so only a local provisioner is a DatabaseDumps and a managed attachment never gets one.
 */
abstract class LocalSql extends LocalProvisioner implements DatabaseDumps
{
    /** The container of the server pod that runs the client. */
    abstract protected function container(): string;

    /** @return list<string> the client command that reads SQL from stdin */
    abstract protected function client(): array;

    abstract protected function dumpPipeline(DatastoreAttachment $attachment): string;

    /** @param  resource  $gzipDump */
    abstract protected function restoreInto(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void;

    public function capabilities(): Capabilities
    {
        return new Capabilities($this);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return SqlConnection::of($attachment);
    }

    public function dump(DatastoreAttachment $attachment, mixed $sink): void
    {
        $this->stream(Kubectl::for($attachment->datastore->cluster), $attachment, $this->dumpPipeline($attachment), output: $sink);
    }

    public function restore(DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        $this->restoreInto(Kubectl::for($attachment->datastore->cluster), $attachment, $gzipDump);
    }

    protected function names(Environment $environment, ?string $name): array
    {
        return DatabaseNames::names($environment, $name ?? DatabaseNames::FIRST_NAME);
    }

    /**
     * @param  resource|null  $stdin
     * @param  resource|null  $output
     */
    protected function stream(Kubectl $kubectl, DatastoreAttachment $attachment, string $pipeline, mixed $stdin = null, mixed $output = null): void
    {
        $command = ['bash', '-c', "set -o pipefail; {$pipeline}"];

        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($attachment->datastore), $command, $stdin, $this->container(), 3600, $output)->throw();
    }

    /** @param  list<string>  $statements */
    protected function run(Kubectl $kubectl, Datastore $datastore, array $statements): void
    {
        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), $this->client(), implode("\n", $statements)."\n", $this->container())->throw();
    }
}

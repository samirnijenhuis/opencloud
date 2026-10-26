<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Models\DatastoreAttachment;

/**
 * One MySQL or Postgres database as the app reaches it, plus its place in the environment: the connection name and
 * whether it is the default.
 */
final readonly class SqlConnection implements Connection
{
    public function __construct(
        public DatastoreKind $kind,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * Every source stores the address in the same columns: the local attach writes the address of the server, and
     * the managed apply writes the outputs of OpenTofu. A managed database has no address before its apply, and no
     * name while OpenTofu destroys it, so it has no connection then.
     */
    public static function of(DatastoreAttachment $attachment): ?self
    {
        if ($attachment->host === null || $attachment->name === null) {
            return null;
        }

        return new self(
            $attachment->datastore->kind,
            $attachment->host,
            $attachment->port,
            $attachment->database_name,
            $attachment->username,
            $attachment->password,
            $attachment->name,
            $attachment->is_default,
        );
    }
}

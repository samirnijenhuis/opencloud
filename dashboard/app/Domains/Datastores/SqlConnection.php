<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Models\DatastoreAttachment;

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

    /**
     * scheme://user:password@host:port/database. The user and the password are percent-encoded, the database name is
     * not. The profile gives the scheme, because frameworks differ on postgres:// and postgresql://.
     */
    public function url(string $scheme): string
    {
        return sprintf('%s://%s:%s@%s:%d/%s', $scheme, rawurlencode($this->username), rawurlencode($this->password), $this->host, $this->port, $this->database);
    }

    public function envFor(EnvProfile $profile): array
    {
        return $profile->database($this);
    }
}

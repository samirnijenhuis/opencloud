<?php

namespace App\Datastores;

use App\Datastores\Env\EnvProfile;

/**
 * One key-value server (Valkey today) as the app reaches it. An environment has at most one, so it has no name.
 */
final readonly class KeyValueConnection implements Connection
{
    /** @param  bool  $tls  false for a server in the cluster. A provider server later uses TLS. */
    public function __construct(
        public string $host,
        public int $port,
        public ?string $password,
        public bool $tls,
    ) {}

    /** redis://:password@host:port, or rediss:// with TLS. The password is percent-encoded. */
    public function url(): string
    {
        $auth = $this->password === null ? '' : ':'.rawurlencode($this->password).'@';

        return sprintf('%s://%s%s:%d', $this->tls ? 'rediss' : 'redis', $auth, $this->host, $this->port);
    }

    public function envFor(EnvProfile $profile): array
    {
        return $profile->keyValue($this);
    }
}

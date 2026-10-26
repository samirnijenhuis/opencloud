<?php

namespace App\Datastores;

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
}

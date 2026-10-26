<?php

namespace App\Datastores;

use App\Datastores\Env\EnvProfile;
use App\Models\DatastoreAttachment;

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

    /** A managed or external store keeps its address on the attachment. A managed one has none before its apply. */
    public static function of(DatastoreAttachment $attachment): ?self
    {
        return $attachment->host === null ? null : new self($attachment->host, $attachment->port, $attachment->password, $attachment->tls);
    }

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

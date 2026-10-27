<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Models\DatastoreAttachment;

/**
 * One S3-compatible bucket as the app reaches it, plus its place in the environment: the name and whether it is the
 * default.
 */
final readonly class BucketConnection implements Connection
{
    /**
     * @param  string  $endpoint  scheme, host, and port, for example http://rustfs-6.oc-datastores.svc.cluster.local:9000
     * @param  string  $region  the region that the SDK signs with. RustFS ignores it.
     * @param  bool  $pathStyle  true when the bucket goes in the path. A bucket host name in the cluster does not resolve.
     */
    public function __construct(
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKeyId,
        public string $secretAccessKey,
        public bool $pathStyle,
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * A managed or external bucket keeps its address on the attachment: the endpoint in host, the bucket in
     * database_name, and the key in username and password. A managed one has none before its apply. S3 SDKs need a
     * region to sign, and most S3-compatible servers ignore it, so us-east-1 stands in when none is stored. A managed
     * bucket has no name while OpenTofu destroys it, so it has no connection then.
     */
    public static function of(DatastoreAttachment $attachment): ?self
    {
        if ($attachment->host === null || $attachment->name === null) {
            return null;
        }

        return new self($attachment->host, $attachment->region ?? 'us-east-1', $attachment->database_name, $attachment->username, $attachment->password, $attachment->path_style, $attachment->name, $attachment->is_default);
    }

    public function envFor(EnvProfile $profile): array
    {
        return $profile->bucket($this);
    }
}

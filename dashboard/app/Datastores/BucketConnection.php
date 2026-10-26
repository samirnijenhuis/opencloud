<?php

namespace App\Datastores;

/**
 * One S3-compatible bucket (RustFS today) as the app reaches it. An environment has at most one, so it has no name.
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
    ) {}
}

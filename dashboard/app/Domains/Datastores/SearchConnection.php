<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Env\EnvProfile;

/**
 * One search server (Meilisearch today) as the app reaches it. An environment has at most one, so it has no name.
 */
final readonly class SearchConnection implements Connection
{
    /** @param  string  $host  scheme, host, and port, for example http://meilisearch-6.oc-datastores.svc.cluster.local:7700 */
    public function __construct(
        public string $host,
        public string $key,
    ) {}

    public function envFor(EnvProfile $profile): array
    {
        return $profile->search($this);
    }
}

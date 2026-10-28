<?php

namespace App\Domains\Datastores\Env;

use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;

/**
 * The env names of one app framework. A profile turns a neutral connection into env values and knows nothing about
 * engines, sources, clusters, or rows. Each method is pure.
 *
 * One method per connection family, so a new family or a new profile cannot load until every pair has names.
 * ApplicationType::envProfiles() gives the profiles of a preset, and AttachmentEnv merges them.
 */
interface EnvProfile
{
    /** @return array<string, EnvValue> */
    public function database(SqlConnection $connection): array;

    /** @return array<string, EnvValue> */
    public function keyValue(KeyValueConnection $connection): array;

    /** @return array<string, EnvValue> */
    public function bucket(BucketConnection $connection): array;

    /**
     * The line that the app adds to its own config to read a database or a bucket that is not the default, or null
     * when the env values are enough. It needs only the kind and the name, so a managed one shows it before its apply.
     */
    public function configLine(DatastoreKind $kind, string $name): ?string;
}

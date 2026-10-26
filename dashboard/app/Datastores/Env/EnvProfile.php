<?php

namespace App\Datastores\Env;

use App\Datastores\BucketConnection;
use App\Datastores\KeyValueConnection;
use App\Datastores\SqlConnection;
use App\Enums\DatastoreKind;

/**
 * The env names of one app framework. A profile turns a neutral connection into env values and knows nothing about
 * engines, sources, clusters, or rows. Each method is pure.
 *
 * One method per connection family, so a new family or a new profile cannot load until every pair has names.
 * AttachmentEnv picks the profile of an environment.
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
     * The line that the app adds to its own config to read a database that is not the default, or null when the
     * env values are enough. It needs only the kind and the name, so a managed database shows it before its apply.
     */
    public function configLine(DatastoreKind $kind, string $name): ?string;
}

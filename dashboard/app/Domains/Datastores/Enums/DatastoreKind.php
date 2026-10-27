<?php

namespace App\Domains\Datastores\Enums;

/**
 * The engine of a datastore. What opencloud can do with it from each source is in Provisioners.
 */
enum DatastoreKind: string
{
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Valkey = 'valkey';
    case Rustfs = 'rustfs';

    /** No default arm, so a new kind does not run until it has a family. */
    public function family(): ResourceFamily
    {
        return match ($this) {
            self::Mysql, self::Postgres => ResourceFamily::Database,
            self::Valkey => ResourceFamily::KeyValue,
            self::Rustfs => ResourceFamily::Bucket,
        };
    }
}

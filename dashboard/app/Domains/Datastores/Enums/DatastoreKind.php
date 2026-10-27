<?php

namespace App\Domains\Datastores\Enums;

/**
 * The engine of a datastore. What opencloud can do with it from each source is in Provisioners.
 */
enum DatastoreKind: string
{
    case MYSQL = 'mysql';
    case POSTGRES = 'postgres';
    case VALKEY = 'valkey';
    case RUSTFS = 'rustfs';

    /** No default arm, so a new kind does not run until it has a family. */
    public function family(): ResourceFamily
    {
        return match ($this) {
            self::MYSQL, self::POSTGRES => ResourceFamily::DATABASE,
            self::VALKEY => ResourceFamily::KEY_VALUE,
            self::RUSTFS => ResourceFamily::BUCKET,
        };
    }
}

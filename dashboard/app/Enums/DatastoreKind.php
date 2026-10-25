<?php

namespace App\Enums;

enum DatastoreKind: string
{
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Valkey = 'valkey';
    case Rustfs = 'rustfs';

    /** A SQL database has a connection name, and an environment can have more than one. */
    public function isSql(): bool
    {
        return $this === self::Mysql || $this === self::Postgres;
    }
}

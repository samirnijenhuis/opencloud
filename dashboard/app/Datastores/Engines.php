<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;

final class Engines
{
    public static function for(DatastoreKind $kind): Engine
    {
        return match ($kind) {
            DatastoreKind::Mysql => new MysqlEngine,
            DatastoreKind::Postgres => new PostgresEngine,
            DatastoreKind::Valkey => new ValkeyEngine,
            DatastoreKind::Rustfs => new RustfsEngine,
        };
    }
}

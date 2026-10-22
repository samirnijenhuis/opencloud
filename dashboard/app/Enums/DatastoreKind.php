<?php

namespace App\Enums;

enum DatastoreKind: string
{
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Valkey = 'valkey';
    case Rustfs = 'rustfs';
}

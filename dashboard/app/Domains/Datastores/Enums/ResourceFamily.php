<?php

namespace App\Domains\Datastores\Enums;

/**
 * The building block an attachment is, whatever its engine and source. The name and one-per-environment rules follow
 * from the family, so a second key-value engine cannot slip past the rule that an environment has one.
 */
enum ResourceFamily: string
{
    case DATABASE = 'database';
    case KEY_VALUE = 'key_value';
    case BUCKET = 'bucket';

    /**
     * An environment can have several databases, each with a connection name, and one of them is the default. It has
     * at most one attachment of each other family, without a name.
     */
    public function isNamed(): bool
    {
        return $this === self::DATABASE;
    }
}

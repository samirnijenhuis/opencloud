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

    /** The name of the first database or bucket of an environment, when the user gives none. */
    public const FIRST_NAME = 'main';

    /**
     * Laravel ships a connection or a disk with each of these names, so a line for one of them in config/database.php
     * or config/filesystems.php would replace it. The default bucket uses the stock s3 disk.
     */
    private const RESERVED = [
        'database' => ['connection', ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv']],
        'bucket' => ['disk', ['local', 'public', 's3']],
    ];

    /**
     * An environment can have several databases and several buckets. Each has a name that is unique in its family,
     * and one of each family is the default. It has at most one key-value store, without a name.
     */
    public function isNamed(): bool
    {
        return $this !== self::KEY_VALUE;
    }

    /** @return string|null the reason the name of a database or a bucket is not valid, or null */
    public function nameError(string $name): ?string
    {
        [$entry, $reserved] = self::RESERVED[$this->value];

        return match (true) {
            preg_match('/^[a-z][a-z0-9_]{0,29}\z/', $name) !== 1 => 'Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.',
            in_array($name, $reserved, true) => "Laravel already has a {$entry} with the name {$name}. Use a different name.",
            default => null,
        };
    }
}

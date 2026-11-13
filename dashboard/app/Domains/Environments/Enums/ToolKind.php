<?php

namespace App\Domains\Environments\Enums;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\SqlConnection;
use App\Domains\Environments\Models\Environment;

/**
 * A dev tool that a preview runs next to its app, on a hostname of its own. Each case holds every fact of its tool, so
 * a new tool is one new case.
 */
enum ToolKind: string
{
    case MAILPIT = 'mailpit';
    case PHPMYADMIN = 'phpmyadmin';

    /** Mailpit takes the mail of the app on this port. */
    public const SMTP_PORT = 1025;

    public function label(): string
    {
        return match ($this) {
            self::MAILPIT => 'Mailpit',
            self::PHPMYADMIN => 'phpMyAdmin',
        };
    }

    /** The tool hostname of a preview starts with this word, for example mail-12.previews.example.com. */
    public function hostnamePrefix(): string
    {
        return match ($this) {
            self::MAILPIT => 'mail',
            self::PHPMYADMIN => 'database',
        };
    }

    public function image(): string
    {
        return match ($this) {
            self::MAILPIT => 'axllent/mailpit:v1.32.0',
            self::PHPMYADMIN => 'phpmyadmin:5.2.3-apache',
        };
    }

    /** The HTTP port of the web UI. Caddy sends the tool hostname to it. */
    public function port(): int
    {
        return match ($this) {
            self::MAILPIT => 8025,
            self::PHPMYADMIN => 80,
        };
    }

    /** @return array<string, int> each named port of the container, the web UI first */
    public function ports(): array
    {
        return match ($this) {
            self::MAILPIT => ['http' => $this->port(), 'smtp' => self::SMTP_PORT],
            self::PHPMYADMIN => ['http' => $this->port()],
        };
    }

    /** @return array{cpu: string, memory: string} the requests and the limits of the container */
    public function resources(): array
    {
        return match ($this) {
            self::MAILPIT => ['cpu' => '50m', 'memory' => '64Mi'],
            self::PHPMYADMIN => ['cpu' => '100m', 'memory' => '128Mi'],
        };
    }

    /** phpMyAdmin opens the default database, so it runs only when that database is MySQL. */
    public function runsIn(Environment $environment): bool
    {
        return match ($this) {
            self::MAILPIT => true,
            self::PHPMYADMIN => self::defaultMysql($environment) !== null,
        };
    }

    /**
     * The env of the tool container. phpMyAdmin logs in to the default database by itself.
     *
     * @return array<string, string>
     */
    public function env(Environment $environment): array
    {
        return match ($this) {
            // Laravel sends AUTH when MAIL_USERNAME is set, so Mailpit takes any user name and password without TLS.
            self::MAILPIT => ['MP_SMTP_AUTH_ACCEPT_ANY' => '1', 'MP_SMTP_AUTH_ALLOW_INSECURE' => '1'],
            self::PHPMYADMIN => self::databaseLogin(self::defaultMysql($environment)),
        };
    }

    /** @return array<string, string> */
    private static function databaseLogin(SqlConnection $database): array
    {
        return ['PMA_HOST' => $database->host, 'PMA_PORT' => (string) $database->port, 'PMA_USER' => $database->username, 'PMA_PASSWORD' => $database->password];
    }

    private static function defaultMysql(Environment $environment): ?SqlConnection
    {
        $attachment = $environment->datastoreAttachments
            ->first(fn (DatastoreAttachment $attachment) => $attachment->is_default && $attachment->datastore->kind === DatastoreKind::MYSQL);

        return $attachment === null ? null : SqlConnection::of($attachment);
    }
}

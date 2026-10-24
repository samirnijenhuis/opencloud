<?php

function verify_env(string $key): ?string
{
    $value = getenv($key);

    return $value === false || $value === '' ? null : $value;
}

/** Connects with the DB_* values that opencloud sets for an attached database. Null when the app has no database. */
function verify_pdo(): ?PDO
{
    $driver = verify_env('DB_CONNECTION');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];

    return match ($driver) {
        null => null,
        'sqlite' => new PDO('sqlite:'.(verify_env('DB_DATABASE') ?? '/var/www/data/app.sqlite'), null, null, $options),
        'mysql', 'pgsql' => new PDO(
            sprintf('%s:host=%s;port=%s;dbname=%s', $driver, verify_env('DB_HOST'), verify_env('DB_PORT'), verify_env('DB_DATABASE')),
            verify_env('DB_USERNAME'),
            verify_env('DB_PASSWORD'),
            $options,
        ),
        default => throw new RuntimeException("Unknown DB_CONNECTION {$driver}."),
    };
}

function verify_tables(PDO $pdo): void
{
    $id = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
        'pgsql' => 'id SERIAL PRIMARY KEY',
        default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
    };
    $pdo->exec("CREATE TABLE IF NOT EXISTS verify_hits ({$id}, created_at VARCHAR(40) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS verify_deploys ({$id}, cell VARCHAR(100) NOT NULL, created_at VARCHAR(40) NOT NULL)");
}

/** Connects with the REDIS_* values that opencloud sets for an attached Valkey. Null when the app has no Valkey. */
function verify_redis(): ?Redis
{
    $host = verify_env('REDIS_HOST');

    if ($host === null) {
        return null;
    }

    $redis = new Redis();
    $redis->connect($host, (int) (verify_env('REDIS_PORT') ?? 6379), 3);

    if (verify_env('REDIS_PASSWORD') !== null) {
        $redis->auth(verify_env('REDIS_PASSWORD'));
    }

    return $redis;
}

/** @return array<string, mixed> */
function verify_report(): array
{
    $report = [
        'fixture' => 'php',
        'marker' => verify_env('VERIFY_MARKER'),
        'build' => trim((string) @file_get_contents('/var/www/build-marker')) ?: null,
        'secret_sha' => verify_env('VERIFY_SECRET') === null ? null : sha1(verify_env('VERIFY_SECRET')),
        'db' => null,
        'cache' => null,
    ];

    try {
        $pdo = verify_pdo();

        if ($pdo !== null) {
            verify_tables($pdo);
            $pdo->prepare('INSERT INTO verify_hits (created_at) VALUES (?)')->execute([date(DATE_ATOM)]);
            $report['db'] = [
                'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'ok' => true,
                'hits' => (int) $pdo->query('SELECT COUNT(*) FROM verify_hits')->fetchColumn(),
                'deploys' => (int) $pdo->query('SELECT COUNT(*) FROM verify_deploys')->fetchColumn(),
            ];
        }
    } catch (Throwable $e) {
        $report['db'] = ['driver' => verify_env('DB_CONNECTION'), 'ok' => false, 'error' => $e->getMessage()];
    }

    try {
        $redis = verify_redis();

        if ($redis !== null) {
            $report['cache'] = ['driver' => 'redis', 'ok' => $redis->ping() !== false, 'hits' => $redis->incr('verify:hits')];
        }
    } catch (Throwable $e) {
        $report['cache'] = ['driver' => 'redis', 'ok' => false, 'error' => $e->getMessage()];
    }

    return $report;
}

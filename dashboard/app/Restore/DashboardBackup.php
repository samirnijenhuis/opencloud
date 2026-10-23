<?php

namespace App\Restore;

use App\Jobs\BackupDashboard;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use PDO;
use RuntimeException;

/**
 * Brings the newest dashboard copy from the backup bucket back as the dashboard database. Nothing is written until
 * the APP_KEY proves that it can read the secrets in the copy.
 */
final class DashboardBackup
{
    /** No worker of the lost server lives on, so its queued jobs and its locks must not come back. */
    private const STALE_TABLES = ['jobs', 'cache', 'cache_locks'];

    /** A copy in the bucket always has a backup target row, so its secret key comes first. */
    private const ENCRYPTED_COLUMNS = [
        ['backup_targets', 'secret_key'],
        ['datastores', 'root_password'],
        ['datastore_attachments', 'password'],
        ['env_vars', 'value'],
        ['provider_credentials', 'token'],
        ['github_apps', 'private_key'],
        ['clusters', 'kubeconfig'],
    ];

    public function __construct(private Filesystem $bucket) {}

    public function newest(): ?string
    {
        return collect($this->bucket->files(BackupDashboard::FOLDER))
            ->filter(fn (string $path) => str_ends_with($path, '.sqlite.gz'))
            ->sort()
            ->last();
    }

    /**
     * Writes the key file first, so a crash never leaves a database without its key.
     *
     * @return string the path of the copy in the bucket
     *
     * @throws RuntimeException when the bucket has no copy, the copy is broken, or the key cannot read it
     */
    public function restore(string $appKey, string $database, string $appKeyFile): string
    {
        $encrypter = self::encrypter($appKey);
        $copy = $this->newest() ?? throw new RuntimeException('The bucket has no dashboard backup in '.BackupDashboard::FOLDER.'. Check the endpoint and the bucket in the restore file.');
        $partial = $database.'.partial';

        try {
            $this->download($copy, $partial);
            $pdo = new PDO('sqlite:'.$partial, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->checkKey($pdo, $encrypter, $copy);

            foreach (self::STALE_TABLES as $table) {
                if (self::hasTable($pdo, $table)) {
                    $pdo->exec("delete from {$table}");
                }
            }

            $pdo = null;
            self::writeAtomically($appKeyFile, $appKey, 0600);
            rename($partial, $database);
        } finally {
            if (is_file($partial)) {
                unlink($partial);
            }
        }

        return $copy;
    }

    private function download(string $copy, string $partial): void
    {
        $gzip = $this->bucket->readStream($copy);
        stream_filter_append($gzip, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
        $out = fopen($partial, 'wb');

        try {
            stream_copy_to_stream($gzip, $out);
        } finally {
            fclose($gzip);
            fclose($out);
        }

        if (file_get_contents($partial, length: 16) !== "SQLite format 3\0") {
            throw new RuntimeException("The backup {$copy} is not a gzip SQLite database.");
        }
    }

    private function checkKey(PDO $pdo, Encrypter $encrypter, string $copy): void
    {
        foreach (self::ENCRYPTED_COLUMNS as [$table, $column]) {
            if (! self::hasTable($pdo, $table)) {
                continue;
            }

            $value = $pdo->query("select {$column} from {$table} where {$column} is not null limit 1")->fetchColumn();

            if ($value === false) {
                continue;
            }

            try {
                $encrypter->decryptString($value);

                return;
            } catch (DecryptException) {
                throw new RuntimeException("The APP_KEY in the restore file cannot read the secrets in {$copy}. Use the APP_KEY of the lost server.");
            }
        }
    }

    private static function encrypter(string $appKey): Encrypter
    {
        $cipher = config('app.cipher');
        $key = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

        if ($key === false || ! Encrypter::supported($key, $cipher)) {
            throw new RuntimeException('The APP_KEY in the restore file is not a valid key. Copy it from /var/lib/opencloud/APP_KEY of the lost server. It starts with base64:.');
        }

        return new Encrypter($key, $cipher);
    }

    private static function hasTable(PDO $pdo, string $table): bool
    {
        $query = $pdo->prepare("select 1 from sqlite_master where type = 'table' and name = ?");
        $query->execute([$table]);

        return $query->fetchColumn() !== false;
    }

    private static function writeAtomically(string $path, string $contents, int $mode): void
    {
        $partial = $path.'.partial';
        touch($partial);
        chmod($partial, $mode);
        file_put_contents($partial, $contents);
        rename($partial, $path);
    }
}

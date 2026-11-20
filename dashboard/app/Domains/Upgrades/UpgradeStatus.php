<?php

namespace App\Domains\Upgrades;

use Illuminate\Filesystem\Filesystem;

/**
 * The state of the last upgrade, in upgrade.json in the data folder. StartUpgrade writes "starting". Then bin/opencloud
 * writes "running", "done", or "failed" on the host, which shares the folder with the dashboard pod.
 */
final readonly class UpgradeStatus
{
    public function __construct(private Filesystem $files) {}

    /** @return array{state: string, from: string, target: string, message: string}|null */
    public function last(): ?array
    {
        return $this->files->exists(self::path()) ? $this->files->json(self::path()) : null;
    }

    public function starting(string $from, string $target): void
    {
        $this->files->put(self::path(), json_encode(['state' => 'starting', 'from' => $from, 'target' => $target, 'message' => ''])."\n");
    }

    private static function path(): string
    {
        return config('opencloud.data_path').'/upgrade.json';
    }
}

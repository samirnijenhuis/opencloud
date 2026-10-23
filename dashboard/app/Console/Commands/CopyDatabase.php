<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `opencloud upgrade` runs this before it changes anything. VACUUM INTO gives a consistent copy while the dashboard runs.
 */
class CopyDatabase extends Command
{
    protected $signature = 'opencloud:copy-database {path : A new file for the copy}';

    protected $description = 'Copy the SQLite database of the dashboard to a new file';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (file_exists($path)) {
            $this->error("The file {$path} exists. Give a new file name.");

            return self::FAILURE;
        }

        DB::statement('VACUUM INTO ?', [$path]);
        $this->line("Copied the database to {$path}.");

        return self::SUCCESS;
    }
}

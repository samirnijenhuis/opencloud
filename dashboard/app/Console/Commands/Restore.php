<?php

namespace App\Console\Commands;

use App\Domains\Restore\Jobs\RunRestore;
use App\Domains\Restore\RestoreState;
use Illuminate\Console\Command;

class Restore extends Command
{
    protected $signature = 'opencloud:restore';

    protected $description = 'Start or resume the restore of the lost server on the local cluster';

    public function handle(): int
    {
        $state = RestoreState::load();

        if ($state === null) {
            $this->error('No restore started on this server. Run the installer with OPENCLOUD_RESTORE_FILE first.');

            return self::FAILURE;
        }

        if ($state->finished()) {
            $this->info('The restore is done.');

            return self::SUCCESS;
        }

        RunRestore::dispatch();
        $this->info('The restore runs in the background. Follow it with: opencloud restore-status');

        return self::SUCCESS;
    }
}

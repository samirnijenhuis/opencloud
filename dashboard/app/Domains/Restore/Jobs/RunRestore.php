<?php

namespace App\Domains\Restore\Jobs;

use App\Domains\Restore\RestoreInstall;
use App\Domains\Restore\RestoreState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Runs the steps of a full restore that are not done. The restore state keeps the progress, so a failed run
 * goes on from its failed step when the owner runs `opencloud restore`.
 */
class RunRestore implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('builds');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('restore'))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(RestoreInstall $restore): void
    {
        $state = RestoreState::load();

        if ($state !== null) {
            $restore->run($state);
        }
    }
}

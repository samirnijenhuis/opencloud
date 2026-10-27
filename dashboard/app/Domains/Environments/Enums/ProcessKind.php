<?php

namespace App\Domains\Environments\Enums;

/** A long-running process runs all the time as a Deployment. A scheduled process runs on its schedule as a CronJob. */
enum ProcessKind: string
{
    case LONG_RUNNING = 'long_running';
    case SCHEDULED = 'scheduled';
}

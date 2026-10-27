<?php

namespace App\Domains\Restore\Enums;

/** A failed or running step runs again on the next run. Only a done step is skipped. */
enum StepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
}

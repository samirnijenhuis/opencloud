<?php

namespace App\Restore;

/** A failed or running step runs again on the next run. Only a done step is skipped. */
enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}

<?php

namespace App\Domains\Backups\Enums;

enum BackupStatus: string
{
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
}

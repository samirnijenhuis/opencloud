<?php

namespace App\Domains\Backups\Enums;

enum BackupStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}

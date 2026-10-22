<?php

namespace App\Backups;

enum BackupStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}

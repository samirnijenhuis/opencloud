<?php

namespace App\Http\Controllers;

use App\Domains\Backups\Actions\BackupNow;
use App\Domains\Backups\Actions\RestoreBackup;
use App\Domains\Backups\Models\Backup;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Http\Requests\RestoreBackupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class BackupController extends Controller
{
    public function store(Request $request, DatastoreAttachment $attachment, BackupNow $backupNow): RedirectResponse
    {
        Gate::authorize('update', $attachment->environment);

        try {
            $backupNow->execute($attachment, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back();
    }

    public function restore(RestoreBackupRequest $request, Backup $backup, RestoreBackup $restoreBackup): RedirectResponse
    {
        try {
            $restoreBackup->execute($backup, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back();
    }
}

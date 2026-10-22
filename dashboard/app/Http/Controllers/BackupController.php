<?php

namespace App\Http\Controllers;

use App\Actions\BackupNow;
use App\Actions\RestoreBackup;
use App\Http\Requests\RestoreBackupRequest;
use App\Models\Backup;
use App\Models\DatastoreAttachment;
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
            $backupNow->handle($attachment, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back();
    }

    public function restore(RestoreBackupRequest $request, Backup $backup, RestoreBackup $restoreBackup): RedirectResponse
    {
        try {
            $restoreBackup->handle($backup, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back();
    }
}

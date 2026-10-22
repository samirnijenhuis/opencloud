<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteBackupTarget;
use App\Actions\StoreBackupTarget;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupTargetRequest;
use App\Models\BackupTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BackupTargetController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->role->allows(Ability::ManageCredentials), 403);

        $target = BackupTarget::query()->latest('id')->first();

        // The keys never leave the server. The page only learns that they are set.
        return Inertia::render('settings/backups', [
            'target' => $target === null ? null : [
                'endpoint' => $target->endpoint,
                'bucket' => $target->bucket,
                'region' => $target->region,
                'path_style' => $target->path_style,
                'keys_set' => true,
            ],
        ]);
    }

    public function update(BackupTargetRequest $request, StoreBackupTarget $storeBackupTarget): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $storeBackupTarget->handle($request->user(), $validated['endpoint'], $validated['bucket'], $validated['access_key'] ?? null, $validated['secret_key'] ?? null, $validated['region'] ?? 'us-east-1', $request->boolean('path_style', true));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['bucket' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, DeleteBackupTarget $deleteBackupTarget): RedirectResponse
    {
        abort_unless($request->user()->role->allows(Ability::ManageCredentials), 403);

        $target = BackupTarget::query()->latest('id')->first();

        if ($target !== null) {
            $deleteBackupTarget->handle($target, $request->user());
        }

        return back();
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Upgrades\Actions\StartUpgrade;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use App\Domains\Upgrades\UpgradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartUpgradeRequest;
use App\Http\Requests\ViewUpdatesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class UpdateController extends Controller
{
    public function edit(ViewUpdatesRequest $request, UpgradeStatus $status): Response
    {
        return Inertia::render('settings/updates', [
            'version' => config('opencloud.version'),
            'latest' => CheckLatestRelease::newerRelease(),
            'lastUpgrade' => $status->last(),
        ]);
    }

    /** The progress page is a full page load, because the dashboard and its scripts restart during the upgrade. */
    public function store(StartUpgradeRequest $request, StartUpgrade $startUpgrade): SymfonyResponse
    {
        $startUpgrade->execute($request->user(), $request->validated('target'));

        return Inertia::location(route('updates.progress'));
    }

    public function progress(ViewUpdatesRequest $request): HttpResponse
    {
        return response()->view('upgrade-progress', [
            'statusUrl' => route('updates.status'),
            'doneUrl' => route('updates.edit'),
        ]);
    }

    public function status(ViewUpdatesRequest $request, UpgradeStatus $status): JsonResponse
    {
        return response()->json(['version' => config('opencloud.version'), 'upgrade' => $status->last()]);
    }
}

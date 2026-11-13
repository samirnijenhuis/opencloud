<?php

namespace App\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Previews\Actions\UpdatePreviewSettings;
use App\Http\Requests\UpdatePreviewSettingsRequest;
use Illuminate\Http\RedirectResponse;

class PreviewSettingsController extends Controller
{
    public function update(UpdatePreviewSettingsRequest $request, Application $application, UpdatePreviewSettings $updatePreviewSettings): RedirectResponse
    {
        $updatePreviewSettings->execute($application, $request->user(), $request->settings());

        return back();
    }
}

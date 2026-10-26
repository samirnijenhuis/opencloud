<?php

namespace App\Http\Controllers;

use App\Actions\UpdateApplicationPreset;
use App\Enums\ApplicationType;
use App\Http\Requests\UpdateApplicationPresetRequest;
use App\Models\Application;
use App\Presets\EnvProfileKind;
use Illuminate\Http\RedirectResponse;

class ApplicationPresetController extends Controller
{
    public function update(UpdateApplicationPresetRequest $request, Application $application, UpdateApplicationPreset $updateApplicationPreset): RedirectResponse
    {
        $updateApplicationPreset->handle(
            $application,
            $request->user(),
            $request->enum('type', ApplicationType::class),
            $request->enum('env_profile', EnvProfileKind::class),
            $request->filled('port') ? $request->integer('port') : null,
        );

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\UpdateApplicationEnvProfile;
use App\Http\Requests\UpdateApplicationEnvProfileRequest;
use App\Models\Application;
use App\Presets\EnvProfileKind;
use Illuminate\Http\RedirectResponse;

class ApplicationEnvProfileController extends Controller
{
    public function update(UpdateApplicationEnvProfileRequest $request, Application $application, UpdateApplicationEnvProfile $updateApplicationEnvProfile): RedirectResponse
    {
        $updateApplicationEnvProfile->handle($application, $request->user(), $request->enum('env_profile', EnvProfileKind::class));

        return back();
    }
}

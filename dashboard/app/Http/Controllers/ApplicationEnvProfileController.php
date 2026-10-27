<?php

namespace App\Http\Controllers;

use App\Domains\Applications\Actions\UpdateApplicationEnvProfile;
use App\Domains\Applications\Enums\EnvProfileKind;
use App\Domains\Applications\Models\Application;
use App\Http\Requests\UpdateApplicationEnvProfileRequest;
use Illuminate\Http\RedirectResponse;

class ApplicationEnvProfileController extends Controller
{
    public function update(UpdateApplicationEnvProfileRequest $request, Application $application, UpdateApplicationEnvProfile $updateApplicationEnvProfile): RedirectResponse
    {
        $updateApplicationEnvProfile->execute($application, $request->user(), $request->enum('env_profile', EnvProfileKind::class));

        return back();
    }
}

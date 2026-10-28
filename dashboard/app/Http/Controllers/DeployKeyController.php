<?php

namespace App\Http\Controllers;

use App\Domains\Applications\Actions\AddDeployKey;
use App\Domains\Applications\Actions\RemoveDeployKey;
use App\Domains\Applications\Models\Application;
use App\Http\Requests\DeployKeyRequest;
use Illuminate\Http\RedirectResponse;

class DeployKeyController extends Controller
{
    public function store(DeployKeyRequest $request, Application $application, AddDeployKey $addDeployKey): RedirectResponse
    {
        $addDeployKey->execute($application, $request->user(), $request->validated('deploy_private_key'));

        return back();
    }

    public function destroy(DeployKeyRequest $request, Application $application, RemoveDeployKey $removeDeployKey): RedirectResponse
    {
        $removeDeployKey->execute($application, $request->user());

        return back();
    }
}

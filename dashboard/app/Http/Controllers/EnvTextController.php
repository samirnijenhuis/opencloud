<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\SaveEnvText;
use App\Domains\Environments\Models\Environment;
use App\Http\Requests\SaveEnvTextRequest;
use Illuminate\Http\RedirectResponse;

class EnvTextController extends Controller
{
    public function update(SaveEnvTextRequest $request, Environment $environment, SaveEnvText $saveEnvText): RedirectResponse
    {
        $saveEnvText->execute($environment, $request->user(), $request->validated('text') ?? '');

        return back();
    }
}

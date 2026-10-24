<?php

namespace App\Http\Controllers;

use App\Actions\SetEnvironmentColor;
use App\Environments\EnvironmentColor;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EnvironmentColorController extends Controller
{
    public function update(Request $request, Environment $environment, SetEnvironmentColor $setColor): RedirectResponse
    {
        Gate::authorize('update', $environment);

        $validated = $request->validate(['color' => ['required', Rule::enum(EnvironmentColor::class)]]);
        $setColor->handle($environment, $request->user(), EnvironmentColor::from($validated['color']));

        return back();
    }
}

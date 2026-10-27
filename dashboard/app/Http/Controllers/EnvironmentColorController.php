<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\SetEnvironmentColor;
use App\Domains\Environments\Enums\EnvironmentColor;
use App\Domains\Environments\Models\Environment;
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
        $setColor->execute($environment, $request->user(), EnvironmentColor::from($validated['color']));

        return back();
    }
}

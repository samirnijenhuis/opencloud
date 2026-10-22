<?php

namespace App\Http\Controllers;

use App\Actions\UpdatePreviewSettings;
use App\Http\Requests\DomainRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreviewSettingsController extends Controller
{
    public function update(Request $request, Application $application, UpdatePreviewSettings $updatePreviewSettings): RedirectResponse
    {
        Gate::authorize('update', $application);

        // The owner may paste the wildcard record, for example "*.preview.example.com".
        $domain = $request->input('preview_domain');
        $request->merge(['preview_domain' => is_string($domain) && trim($domain) !== '' ? DomainRequest::normalizeHostname(preg_replace('/^\s*\*\./', '', $domain)) : null]);

        $validated = $request->validate([
            'previews_enabled' => ['required', 'boolean'],
            'allow_forks' => ['required', 'boolean'],
            'preview_domain' => ['nullable', 'string', 'max:200', 'regex:'.StoreApplicationRequest::HOSTNAME],
        ], ['preview_domain.regex' => 'Enter a domain like preview.example.com.']);

        $updatePreviewSettings->handle($application, $request->user(), [
            'previews_enabled' => (bool) $validated['previews_enabled'],
            'allow_forks' => (bool) $validated['allow_forks'],
            'preview_domain' => $validated['preview_domain'],
        ]);

        return back();
    }
}

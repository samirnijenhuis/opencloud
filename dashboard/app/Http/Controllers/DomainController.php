<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\Actions\AddDomain;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\Domain;
use App\Http\Requests\DomainRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DomainController extends Controller
{
    /** The canvas shows this now. Old links still work. */
    public function index(Environment $environment): RedirectResponse
    {
        return to_route('environments.show', $environment);
    }

    public function store(DomainRequest $request, Environment $environment, AddDomain $addDomain): RedirectResponse
    {
        $addDomain->handle(
            $environment,
            $request->user(),
            $request->validated('hostname'),
            manageDns: $request->boolean('manage_dns'),
            proxied: $request->boolean('proxied'),
        );

        return back();
    }

    public function check(Domain $domain): RedirectResponse
    {
        Gate::authorize('update', $domain->environment->application);

        CheckDomain::dispatch($domain);

        return back();
    }

    public function destroy(Request $request, Domain $domain, RemoveDomain $removeDomain): RedirectResponse
    {
        Gate::authorize('update', $domain->environment->application);

        $removeDomain->handle($domain, $request->user());

        return back();
    }
}

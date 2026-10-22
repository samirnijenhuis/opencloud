<?php

namespace App\Http\Controllers;

use App\Actions\AddDomain;
use App\Actions\RemoveDomain;
use App\Domains\CloudflareDns;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\DomainRequest;
use App\Jobs\CheckDomain;
use App\Models\Domain;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    use PresentsEnvironment;

    public function index(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        return Inertia::render('environments/domains', [
            ...$this->environmentProps($environment, $request->user()),
            'ingressIp' => $environment->application->cluster->ingress_ip,
            'cloudflareZones' => array_column(CloudflareDns::credential()?->details['zones'] ?? [], 'name'),
            'domains' => $environment->domains()->orderBy('id')->get()->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'dns_status' => $domain->dns_status,
                'dns_mode' => $domain->dns_mode,
                'dns_found' => $domain->dns_found ?? [],
                'certificate_status' => $domain->certificate_status,
                'dns_checked_at' => $domain->dns_checked_at?->toIso8601String(),
            ]),
        ]);
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

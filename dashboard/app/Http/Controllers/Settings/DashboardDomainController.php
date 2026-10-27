<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Members\Enums\Ability;
use App\Domains\Routing\Actions\RemoveDashboardDomain;
use App\Domains\Routing\Actions\SetDashboardDomain;
use App\Domains\Routing\Jobs\CheckDashboardDomain;
use App\Domains\Routing\Models\DashboardDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardDomainRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardDomainController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeOwner($request);

        $domain = DashboardDomain::current();

        return Inertia::render('settings/dashboard-address', [
            'address' => DashboardDomain::address(),
            'ingressIp' => DashboardDomain::localCluster()?->ingress_ip,
            'domain' => $domain === null ? null : [
                'hostname' => $domain->hostname,
                'dns_status' => $domain->dns_status,
                'dns_found' => $domain->dns_found ?? [],
                'dns_checked_at' => $domain->dns_checked_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(DashboardDomainRequest $request, SetDashboardDomain $setDashboardDomain): RedirectResponse
    {
        $setDashboardDomain->execute($request->user(), $request->validated('hostname'));

        return back();
    }

    public function check(Request $request): RedirectResponse
    {
        $this->authorizeOwner($request);

        $domain = DashboardDomain::current();

        if ($domain !== null) {
            CheckDashboardDomain::dispatch($domain);
        }

        return back();
    }

    public function destroy(Request $request, RemoveDashboardDomain $removeDashboardDomain): RedirectResponse
    {
        $this->authorizeOwner($request);

        $domain = DashboardDomain::current();

        if ($domain !== null) {
            $removeDashboardDomain->execute($domain, $request->user());
        }

        return back();
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->role->allows(Ability::MANAGE_CREDENTIALS), 403);
    }
}

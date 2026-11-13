<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\WakeEnvironment;
use App\Domains\Routing\Models\Domain;
use App\Http\Requests\WakeEnvironmentRequest;
use Illuminate\Http\Response;

class WakeController extends Controller
{
    /** Caddy sends each request whose app does not answer here, with the hostname of the request in the path. */
    public function __invoke(WakeEnvironmentRequest $request, Domain $domain, WakeEnvironment $wake): Response
    {
        if (! $wake->execute($domain->environment, null)->isWaking()) {
            return response('The app does not answer. Try again in a minute.', 502)->header('Content-Type', 'text/plain');
        }

        // The page reloads the address of the visitor, which goes through Caddy again and reaches the app once it is ready.
        return response()->view('starting', ['hostname' => $domain->hostname], 503)
            ->header('Retry-After', '3')
            ->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Middleware;

use App\Domains\Applications\Models\Application;
use App\Domains\Members\Enums\Ability;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'can' => [
                'create_application' => $request->user()?->can('create', Application::class) ?? false,
                'manage_credentials' => $request->user()?->role->allows(Ability::MANAGE_CREDENTIALS) ?? false,
                'view_audit_log' => $request->user()?->role->allows(Ability::MANAGE_APPLICATIONS) ?? false,
            ],
            // Guests do not learn the version, because it tells an attacker which known bugs apply.
            'opencloud' => fn () => $request->user() === null ? null : [
                'version' => config('opencloud.version'),
                'update' => $request->user()->role->allows(Ability::UPGRADE_OPENCLOUD) ? CheckLatestRelease::newerRelease() : null,
            ],
        ]);
    }
}

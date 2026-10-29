<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Webhooks prove themselves with a signature, so they need no session, cookies, or CSRF token.
        api: __DIR__.'/../routes/webhooks.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Caddy sends the original scheme and host. It reaches the dashboard from the pod network, so trust every sender.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // A password change logs out every other session of the user.
            AuthenticateSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A refused form must not leave the kubeconfig, the registry password, a deploy key, or an env value in the session table.
        $exceptions->dontFlash(['kubeconfig', 'registry_password', 'deploy_private_key', 'env', 'value']);
    })->create();

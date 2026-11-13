<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    {{-- The page lives on the hostname of the app, so it loads nothing from the dashboard. --}}
    <meta http-equiv="refresh" content="3">
    <title>Starting {{ $hostname }}</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 1rem 0 0.5rem; }
        p { margin: 0; opacity: 0.7; line-height: 1.5; }
        .spinner { width: 1.5rem; height: 1.5rem; margin: 0 auto; border: 2px solid; border-right-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
    </style>
</head>
<body>
    <main>
        <div class="spinner" role="presentation"></div>
        <h1>Starting {{ $hostname }}</h1>
        <p>This site was asleep because it had no visitors. It starts now. The page loads the site when it is ready.</p>
    </main>
</body>
</html>

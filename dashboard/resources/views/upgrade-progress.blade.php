<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    {{-- The dashboard restarts during the upgrade, so the page loads nothing from it and only polls the status. --}}
    <title>Updating opencloud</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 1rem 0 0.5rem; }
        p { margin: 0; opacity: 0.7; line-height: 1.5; }
        a { color: inherit; }
        #link { display: inline-block; margin-top: 1rem; }
        .spinner { width: 1.5rem; height: 1.5rem; margin: 0 auto; border: 2px solid; border-right-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
    </style>
</head>
<body>
    <main>
        <div class="spinner" id="spinner" role="presentation"></div>
        <h1 id="title">Updating opencloud</h1>
        <p id="text" role="status">The update starts now. Your apps keep running.</p>
        <a id="link" href="{{ $doneUrl }}" hidden>Back to the dashboard</a>
    </main>
    <script>
        (() => {
            const statusUrl = @json($statusUrl);
            const doneUrl = @json($doneUrl);
            const opened = Date.now();
            // A start that systemd never picked up stays "starting". The upgrade itself writes "running" within seconds.
            const startLimit = 120000;

            const show = (title, text, final) => {
                document.getElementById('title').textContent = title;
                document.getElementById('text').textContent = text;
                document.getElementById('spinner').hidden = final;
                document.getElementById('link').hidden = !final;
            };

            const poll = async () => {
                let status = 0;
                let data = null;
                try {
                    const response = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                    status = response.status;
                    data = response.ok ? await response.json() : null;
                } catch {
                    // The dashboard is offline while its pod restarts, or a proxy answered with an error page.
                    data = null;
                }

                if (status === 401 || status === 403) {
                    show('Sign in again', 'Opencloud cannot show the update to this session. Sign in and open Settings, then Updates.', true);
                    return;
                }

                const upgrade = data ? data.upgrade : null;

                if (data === null) {
                    show('Opencloud restarts', 'The dashboard is offline for about a minute. Your apps keep running.', false);
                } else if (upgrade === null) {
                    show('No update runs', 'Open Settings, then Updates, to start an update.', true);
                    return;
                } else if (upgrade.state === 'done' && data.version === upgrade.target) {
                    show(`Opencloud ${upgrade.target} runs`, 'The dashboard opens now.', false);
                    window.location.assign(doneUrl);
                    return;
                } else if (upgrade.state === 'failed') {
                    show('The update failed', upgrade.message, true);
                    return;
                } else if (upgrade.state === 'starting' && Date.now() - opened > startLimit) {
                    show('The update did not start', `Opencloud ${upgrade.from} still runs. Run "sudo opencloud upgrade ${upgrade.target}" on the server to see why.`, true);
                    return;
                } else {
                    show(`Updating to ${upgrade.target}`, `Opencloud ${upgrade.from} runs until the new version starts. Your apps keep running.`, false);
                }

                setTimeout(poll, 2000);
            };

            poll();
        })();
    </script>
</body>
</html>

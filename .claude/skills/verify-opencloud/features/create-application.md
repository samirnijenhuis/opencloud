# Create an application

A user creates an application from a GitHub repository and a branch, picks the app type, size, database, and commands, and gets a live site at a free HTTPS address without a DNS step.

## Sub-features

- `create-laravel` creates a Laravel app, which opencloud builds with its own Dockerfile template.
- `create-dockerfile` creates an app from the Dockerfile at the root of the branch, with a port.
- `create-first-deploy` starts the first deployment, which goes live without a click.
- `create-free-address` gives the production environment the address `<app>-production.<ip>.sslip.io` with a certificate.
- `create-no-processes` creates the app with no worker and no scheduler when the form sends an empty process list.

## How to get to it (user POV)

- Choose `Applications`, then `New application`, fill in the form, and choose `Create and deploy`.
- The form posts to `POST /applications` and goes to `/environments/<id>`.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Laravel app.** Create a Laravel app with MySQL. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-none'`. The output shows `PASS the form creates the application (302 .../environments/<id>)` and `PASS deployment 1 goes live`.
- **Dockerfile apps.** Create the static and PHP apps. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'static-none-none' --only 'php-none-none'`. Both cells show `PASS step 1: the page answers 200 over HTTPS`.
- **Free address.** Read the address from the environment page. The output shows `PASS the environment has a free address (<cell>-production.<ip>.sslip.io)`. `canvas.json` lists that domain with `"generated": true`.
- **Proof.** Open `.verify/runs/<run id>/<cell>/step-1-page.html`. It holds the `<title>` of the fixture, for example `opencloud verify: static`.

## Gotchas

- The create form needs a full `https://` repository URL or `owner/repo`. A `http://` URL fails validation.
- Without `processes`, a Laravel app gets a worker and a scheduler. The runner sends `processes: []` to keep each cell small.
- The first build of a fixture on a new machine can take several minutes. The runner waits up to 40 minutes for it.
- A second run on the same machine reuses the app names, so the free address gets the app id. Read the address from the page, never build it from the name.

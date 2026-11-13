# Create an application

A user creates an application from a GitHub repository and a branch, and picks the app type. The app gets the defaults of its preset. The user configures the environment on the canvas and the settings page, clicks Deploy, and gets a live site at a free HTTPS address without a DNS step.

## Sub-features

- `create-laravel` creates a Laravel app, which opencloud builds with its own Dockerfile template.
- `create-dockerfile` creates an app from the Dockerfile at the root of the branch, with a port. The app gets the Laravel env names and the generic env names.
- `create-preset` creates a Node.js, static, Python, or Ruby app with no Dockerfile. Opencloud writes the Dockerfile from the preset, and the app gets the generic env names.
- `create-first-deploy` is the first deployment. Create starts no deployment. The user clicks Deploy after the configuration, and the deployment goes live.
- `create-free-address` gives the production environment the address `<app>-production.<ip>.sslip.io` with a certificate.
- `create-no-processes` removes the worker and the scheduler that a Laravel app gets from its preset. The user removes each process on the canvas.
- `create-own-deploy-key` creates the app from the private fixture repository with the choice "Use my own key". The dashboard reads the branch over SSH with that key.

## How to get to it (user POV)

- Choose `Applications`, then `New application`, fill in the repository, the branch, the name, the cluster, the type, and the port, and choose the create button.
- The form posts to `POST /applications` and goes to `/environments/<id>`. No deployment starts.
- On the canvas, remove a process with `DELETE /processes/<id>`. Add or remove a database with `POST /environments/<id>/datastores` and `DELETE /datastore-attachments/<id>`.
- On the settings page, save the build command and the deploy commands with `PATCH /environments/<id>/settings`. Save an env value with `POST /environments/<id>/env`.
- Choose `Deploy`. It posts to `POST /environments/<id>/deployments` and goes to `/deployments/<id>`.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Laravel app.** Create a Laravel app with MySQL. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-none'`. The output shows `PASS the form creates the application (302 .../environments/<id>)`, `PASS the process worker is removed`, `PASS the settings save the build command and the deploy commands`, `PASS mysql becomes ready`, `PASS Deploy starts the first deployment`, and `PASS deployment 1 goes live`. A `laravel-postgres-none` cell shows `PASS the preset mysql database is removed` and `PASS Add resource attaches postgres`.
- **Dockerfile apps.** Create the static and PHP apps. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'static-none-none' --only 'php-none-none'`. Both cells show `PASS step 1: the page answers 200 over HTTPS`.
- **Apps with no Dockerfile.** Create the Node.js, static site, Python, and Ruby apps. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'node-none-none' --only 'site-none-none' --only 'python-none-none' --only 'ruby-none-none'`. Each cell shows `PASS deployment 1 goes live` and `PASS step 1: the page answers 200 over HTTPS`.
- **Before a run.** Prove a new fixture and its preset in Docker on this computer. Run `.claude/skills/verify-opencloud/scripts/matrix.py local --set full --only 'node-none-none'`. The output shows `PASS the image builds with the Dockerfile of the preset (.opencloud.Dockerfile, see build.log)`.
- **Free address.** Read the address from the environment page. The output shows `PASS the environment has a free address (<cell>-production.<ip>.sslip.io)`. `canvas.json` lists that domain with `"generated": true`, `"primary": true`, and `"certificate_status": "issued"`.
- **Proof.** Open `.verify/runs/<run id>/<cell>/step-1-page.html`. It holds the `<title>` of the fixture, for example `opencloud verify: static`. In `canvas.json`, `processes` is `[]`. The checks `the process <name> is removed` prove `create-no-processes` for a Laravel cell. For the other presets, read the file, because they have no process to remove.

## Gotchas

- The create form needs a full `https://` repository URL, `owner/repo`, or `git@github.com:owner/repo.git`. A `http://` URL fails validation.
- The fixture repository is private. Without `.verify/fixtures-deploy-key`, `matrix.py run` stops. A key that GitHub does not have fails each cell at `the form creates the application`, because the form redirects back and not to the environment. `matrix.py doctor` names the cause. Run `matrix.py fixtures key`.
- A Laravel app gets a worker, a scheduler, the deploy command `php artisan migrate --force`, and MySQL from its preset. The other presets get no process and no database. The create form drops the fields `size`, `processes`, `env`, `domain`, `database`, and the commands, and creation starts no deployment. The runner removes the two processes to keep each cell small, replaces the deploy commands, and removes MySQL when the cell wants no database or Postgres.
- The runner sets the datastores before the env values. A datastore sets its own env names, such as `DB_CONNECTION`, and the env form refuses those names.
- The first build of a fixture on a new machine can take several minutes. The runner waits up to 40 minutes for it.
- A second run on the same machine reuses the app names, so the free address gets the app id. Read the address from the page, never build it from the name.
- The `python` and `ruby` cells need the Python and Ruby presets. On a checkout without them, the create form refuses the type, and `matrix.py local` fails at `local-plan.php`.
- The `python` and `ruby` fixtures start with the first `web:` line of their `Procfile`. The line must bind `0.0.0.0:$PORT`.

---
name: verify-opencloud
description: Proves that opencloud deploys working sites across a matrix of app types (static HTML, plain PHP, Laravel), databases (none, MySQL, Postgres, MySQL plus Postgres, SQLite), and Valkey. Each cell creates an app through the dashboard routes with its own build command, deploy commands, and env values, then redeploys it twice and checks the live HTTPS page each time. Use it after a change to deploys, builds, datastores, env values, Caddy, or the dashboard forms, and before you tell the user that deploys work.
---

# Verify opencloud with the deploy matrix

Opencloud is a self-hosted deploy platform. The user's surface is the dashboard (Laravel, Inertia, React) at `http://<machine ip>:8000`. The result that matters is a live site at `https://<app>-production.<ip>.sslip.io`. This skill drives the dashboard over HTTP, the way its forms post, on a disposable OrbStack machine. Then it checks each deployed site.

Everything runs from the repository root. The helpers are in `.claude/skills/verify-opencloud/scripts/`.

## The matrix

`matrix.json` is the source of the cells. A cell id is `<fixture>-<database>-<cache>`, for example `laravel-postgres-valkey`.

- **Fixtures.** Each one is an app on its own branch of `https://github.com/samirnijenhuis/opencloud-verify-fixtures`. The folders in `fixtures/` are the source.
  - `static` is nginx. Its build reads a build env value from a BuildKit secret, and its deploy command is an `echo`.
  - `php` is plain PHP on Apache with `pdo_mysql`, `pdo_pgsql`, and `redis`. Its deploy command is `php /var/www/deploy.php <cell>`.
  - `laravel` is laravel/laravel at the commit in `matrix.json`, plus a `/verify` route, the command `verify:mark-deploy`, and a migration. Its build command writes a marker file. Its deploy commands are `migrate` and `verify:mark-deploy`.
- **Databases.**
  - `none` attaches no database.
  - `mysql` and `postgres` use the database option of the create form for a Laravel app. The form offers no database for a Dockerfile app, so the cell adds it with "Add resource" before the second deploy, the way a user does.
  - `mysqlpostgres` creates the app with MySQL and then adds Postgres. Opencloud refuses that by design: one SQL database per environment. The cell checks the refusal and keeps working on MySQL.
  - `sqlite` sets `DB_CONNECTION=sqlite`. Laravel bakes a migrated file into the image with its build command. PHP writes to a file in the pod. Each pod has its own copy, and a redeploy starts empty, so the matrix checks no persistence for SQLite.
- **Caches.**
  - `none` attaches no cache.
  - `valkey` adds Valkey with "Add resource" after the first deploy, the way a user does. Laravel also gets `CACHE_STORE=redis`.
- **Sets.**
  - `smoke` is 8 cells that cover each value at least once. It took 11 minutes in a measured run.
  - `full` is all 30 cells. It took 41 minutes in a measured run.
  - A new machine adds about 4 minutes, and the first build of each fixture on it adds a few more.

Each cell does three deploys:

1. **Create.** The cell posts the create form with its build command, deploy commands, env values, a secret, a build env value, and `processes: []`. The first deploy must go live. Then the page and the verify endpoint must report the runtime env value, the build marker, the hash of the secret, the database, and the cache.
2. **Change and redeploy.** The cell changes `VERIFY_MARKER` to `-v2`, adds Valkey when the cell has it, and clicks Deploy. The new value must show. The MySQL and Postgres rows from step 1 must still be there.
3. **Redeploy under traffic.** The cell polls the verify endpoint four times a second during a third deploy. No request may fail.

Each deploy log must contain `deploy-marker-<cell>`, which proves that the deploy commands ran. At the end, the settings page must not contain the secret value.

## Launch

```bash
.claude/skills/verify-opencloud/scripts/machine.sh up
```

- The script builds the dashboard and Caddy images from this checkout with `scripts/try-on-mac.sh`, and installs them on the OrbStack machine `oc-verify`. Set `OC_VERIFY_MACHINE` to use another name.
- A new machine takes 3 to 5 minutes. A second `up` updates the same machine to the current code, and its apps stay.
- The machine is ready when the script prints `Wrote .../.verify/state/machine.env`. That file holds `MACHINE`, `BASE`, `SETUP_URL`, and `BUILT_FROM`, the commit of the build.
- The first `matrix.py run` or `doctor` creates the owner through the setup URL. Its random password goes to `.verify/state/owner.json` (mode 600). Do not print it.

## Doctor

```bash
.claude/skills/verify-opencloud/scripts/matrix.py doctor
```

Run it first, and again whenever something looks wrong. It changes nothing on the machine. It checks that:

- the machine runs;
- `BASE/up` answers 200;
- `BUILT_FROM` is the current `HEAD`. If not, run `machine.sh up` again, or the run tests old code;
- the login works;
- the cluster capacity is readable;
- the fixture branches match `fixtures/`. If not, run `matrix.py fixtures sync`.

## Drive

```bash
.claude/skills/verify-opencloud/scripts/matrix.py list                                  # the smoke cells
.claude/skills/verify-opencloud/scripts/matrix.py run                                   # the smoke set
.claude/skills/verify-opencloud/scripts/matrix.py run --set full                        # all 30 cells
.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-*' --only 'php-sqlite-*'
```

The runner prints one `PASS` or `FAIL` line per check and a summary table. It exits 0 only when every cell passes. Cells run one after another, because the dashboard builds one image at a time.

The runner posts JSON to the same routes as the dashboard forms, with the session cookie and the `X-XSRF-TOKEN` header of a browser:

- `POST /applications` is the create form.
- `POST /environments/{id}/env` is Settings, then Environment values.
- `POST /environments/{id}/datastores` with `{"kind": "valkey"}` is Add resource.
- `POST /environments/{id}/deployments` is the Deploy button.

It reads the page props from the `data-page` attribute of `GET /environments/{id}`, `/deployments/{id}`, and `/applications/create`. It checks the sites with HTTPS requests to the free address. The test machines use Caddy's local CA, so the runner does not check the certificate chain.

To add a cell, add a value to `databases` or `caches` in `matrix.json` and teach `Cell` in `matrix.py` what it means. To add a fixture, add a folder in `fixtures/`, an entry in `fixtures` of `matrix.json`, and run `matrix.py fixtures sync`. A fixture must answer `/` with a `<title>`. Its verify endpoint must return JSON with `marker`, `build`, and, when it reports them, `secret_sha`, `db`, and `cache`.

## Evidence

Each run writes to `.verify/runs/<YYYYMMDD-HHMMSS>/`, which git ignores:

- `summary.md` holds one row per cell: the verdict, the seconds, the first deploy time, and the failed checks.
- `machine.json` holds the machine, `BASE`, `BUILT_FROM`, the `HEAD` at the run, and the cells.
- `<cell>/` holds:
  - `config.json`, the body of the create form, with the secret hidden;
  - `deployment-1.log` to `deployment-3.log`, the deploy logs;
  - `step-N-page.html` and `step-N-verify.json`, the live responses of each step;
  - `step-3-requests.json`, the request count and the failures during the third deploy;
  - `canvas.json`, the environment page props at the end;
  - `result.json`, each check with its verdict and detail.

Proof standards:

- A cell passes only when the live HTTPS response shows the values that the step set. A green deployment status is not proof.
- Report a failed cell with its failed checks and the matching log or response file. Never report a cell that you did not run as verified.
- The runner uses the real build, deploy, Caddy, and DNS path. It mocks nothing.

## Cleanup

```bash
.claude/skills/verify-opencloud/scripts/machine.sh down
```

- This deletes the machine named in `OC_VERIFY_MACHINE` (default `oc-verify`), with all its apps. It also deletes the login state in `.verify/state/`. The evidence in `.verify/runs/` stays.
- Opencloud has no action that deletes an app, so a run leaves its apps on the machine. A full run on a used machine can fill it. Run `down` and `up` between large runs.
- The fixture repository stays on GitHub. Do not delete it.

## Isolation and gotchas

- Never use the machine `opencloud`. It holds the user's own apps, and `machine.sh` refuses that name.
- `scripts/try-on-mac.sh` keeps its build output in `.try/`. Do not run two `machine.sh up` commands, or `up` next to another `try-on-mac.sh`, at the same time.
- A second run on the same machine reuses the app names. The free address then gets the app id in its name. The runner reads the address from the page props, so this is harmless.
- A Dockerfile app ignores the build command. Only the Laravel template runs it. Fixtures read build env values with `RUN --mount=type=secret,id=<KEY>`.
- The first build of each fixture on a new machine downloads every package and can take several minutes. Later builds use the BuildKit cache and take seconds.
- `matrix.py fixtures sync` pushes to `samirnijenhuis/opencloud-verify-fixtures` with the `gh` login. Someone without push rights can still run the matrix, because the repository is public.

## Feature map

`features/README.md` lists the user-facing features that this skill verifies, with the routes, the proof, and the gotchas of each. Read the matching file before you change a check or verify one feature alone.

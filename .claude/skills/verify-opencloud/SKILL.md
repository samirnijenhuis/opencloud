---
name: verify-opencloud
description: Proves that opencloud deploys working sites across a matrix of app types (a Dockerfile site, plain PHP, Laravel, Node.js, a static site, Python, Ruby), databases (none, MySQL, Postgres, MySQL plus Postgres, SQLite), and Valkey, with the Laravel and the generic env names. Each cell creates an app through the dashboard routes with its own build command, deploy commands, and env values, then redeploys it twice and checks the live HTTPS page each time. Use it after a change to deploys, builds, datastores, env values, Caddy, or the dashboard forms, and before you tell the user that deploys work.
---

# Verify opencloud with the deploy matrix

Opencloud is a self-hosted deploy platform. The user's surface is the dashboard (Laravel, Inertia, React) at `http://<machine ip>:8000`. The result that matters is a live site at `https://<app>-production.<ip>.sslip.io`. This skill drives the dashboard over HTTP, the way its forms post, on a disposable OrbStack machine. Then it checks each deployed site.

Everything runs from the repository root. The helpers are in `.claude/skills/verify-opencloud/scripts/`.

## The matrix

`matrix.json` is the source of the cells. A cell id is `<fixture>-<database>-<cache>`, for example `laravel-postgres-valkey`.

- **Fixtures.** Each one is an app on its own branch of `https://github.com/samirnijenhuis/opencloud-verify-fixtures`. The repository is private, so each cell reads it with the deploy key of the matrix. The folders in `fixtures/` are the source. The preset of a fixture decides its env names. `laravel` gets the Laravel env names. `static` and `php` are Dockerfile apps, so they get the Laravel names and the generic names, and `php` reads the Laravel names. The other four get the generic env names: `DATABASE_URL`, `<NAME>_DATABASE_URL`, and `REDIS_URL`.
  - `static` is nginx with its own Dockerfile, so it proves the Dockerfile preset. Its build reads a build env value from a BuildKit secret, and its deploy command is `echo deploy-marker-'<cell>'`.
  - `php` is plain PHP on Apache with `pdo_mysql`, `pdo_pgsql`, and `redis`, with its own Dockerfile. Its deploy command is `php /var/www/deploy.php <cell>`.
  - `laravel` is laravel/laravel at the commit in `matrix.json`, plus a `/verify` route, the command `verify:mark-deploy`, and a migration. Its build command writes a marker file. Its deploy commands are `migrate` and `verify:mark-deploy`.
  - `node` is a `node:http` server with `pg`, `mysql2`, and `redis`, locked in `package-lock.json`. The Node.js preset builds it. Its build script writes the build env value into a file. Its deploy command is `node deploy.js <cell>`.
  - `site` is a static site that the static preset builds. Its build script writes the build env value into `dist/index.html` and `dist/verify.json`, and nginx serves `dist`. Its deploy command is `echo deploy-marker-'<cell>'`.
  - `python` is Flask on gunicorn with `psycopg`, `PyMySQL`, and `redis`, pinned in `requirements.txt`. The Python preset builds it. Its build command writes the build env value into a file. Its deploy command is `python deploy.py <cell>`.
  - `ruby` is Sinatra on puma with `pg`, `mysql2`, and `redis`, locked in `Gemfile.lock`. The Ruby preset builds it. Its build command writes the build env value into a file. Its deploy command is `bundle exec ruby deploy.rb <cell>`.
  - The `python` and `ruby` fixtures start with the `web:` line of their `Procfile`, which binds `0.0.0.0:$PORT`.
- **Checks.** The `checks` of a fixture list what its verify endpoint reports: `marker` (the runtime env value), `build`, `secret`, `db`, `extra` (the database `reports`), and `cache`. The runner prints `SKIP` for a check that the fixture does not report. A skip is not a pass. `static` reports `marker` and `build`. `site` has no server process, so it reports only `build`. The other fixtures report all six.
- **Databases.** The `databases` of a fixture list the values that it supports.
  - `none` attaches no database.
  - `mysql` and `postgres` exist before the first deploy for a fixture with `database_before_first_deploy`: `laravel`, `node`, `python`, and `ruby`. The runner removes the other database of the preset and adds the missing one on the canvas. A Laravel app starts with MySQL, so the `laravel` cells with `postgres` remove it first. The `static` and `php` cells add the database with "Add resource" before the second deploy, so the matrix proves both paths.
  - `mysqlpostgres` creates the app with MySQL, the default database `main`. In step 2 it adds Postgres with Add resource and the connection name `reports`. The app gets `REPORTS_DB_URL` with the Laravel env names and `REPORTS_DATABASE_URL` with the generic env names. A Dockerfile app gets both. The verify endpoint connects with the name that its fixture reads. From step 2 on, `extra.reports` must report `ok` with the driver `pgsql`.
  - `sqlite` sets `DB_CONNECTION=sqlite`. Laravel bakes a migrated file into the image with its build command. PHP writes to a file in the pod. Each pod has its own copy, and a redeploy starts empty, so the matrix checks no persistence for SQLite. The generic fixtures have no `sqlite` cells.
  - `site` supports only `none`.
- **Caches.**
  - `none` attaches no cache.
  - `valkey` adds Valkey with "Add resource" after the first deploy, the way a user does. Laravel also gets `CACHE_STORE=redis`. Without Valkey, Laravel reports the cache in `cache_without_valkey`, and the other fixtures report no cache.
- **Sets.**
  - `smoke` is 12 cells that cover each value of each fixture at least once. Its first 8 cells took 11 minutes in a measured run. The 4 cells of `node`, `site`, `python`, and `ruby` have no measured run yet.
  - `full` is all 55 cells. Its first 30 cells took 41 minutes in a measured run.
  - A new machine adds about 4 minutes, and the first build of each fixture on it adds a few more.

Each cell does three deploys:

1. **Create and configure.** The cell posts the create form with the repository, the branch, the name, the cluster, the type, the port, and the private key of the matrix in `deploy_private_key`, as the choice "Use my own key" does. The dashboard reads the branch over SSH with that key and starts no deploy. Then the cell configures production as a user does. It removes the processes of the preset, saves the build command and the deploy commands in the settings, makes the database that the cell has before the first deploy, and adds the env values with a secret and a build env value. Last, it clicks Deploy. The first deploy must go live. Then the page and the verify endpoint must report the runtime env value, the build marker, the hash of the secret, the database, and the cache, as far as the fixture reports them.
2. **Change and redeploy.** The cell changes `VERIFY_MARKER` to `-v2`, adds the database `reports` and Valkey when the cell has them, and clicks Deploy. The new value must show. The MySQL and Postgres rows from step 1 must still be there.
3. **Redeploy under traffic.** The cell polls the verify endpoint four times a second during a third deploy. No request may fail.

Each deploy log must contain `deploy-marker-<cell>` in its step "Running deploy commands", which proves that the deploy commands ran. That step also shows the command line, so a deploy command must not hold the marker as plain text. The `echo` commands of `static` and `site` put the cell id in quotes for this reason. A live deployment must show each of the 7 steps as finished. The runner reads the steps from the deployment page and writes them as `deployment-N.log`. At the end, the settings page must not contain the secret value.

## Deploy key

```bash
.claude/skills/verify-opencloud/scripts/matrix.py fixtures key
```

- The matrix keeps one key pair for the fixture repository in `.verify/fixtures-deploy-key` (mode 600) and `.verify/fixtures-deploy-key.pub`. Git ignores `.verify/`. Do not print the private key.
- `fixtures key` makes the pair with `ssh-keygen -t ed25519` when it is missing. Then it reads the deploy keys of the repository with `gh api repos/<owner>/<repo>/keys`. When GitHub does not have the public key, it adds it as a read-only key with the title `opencloud verify matrix`. It never adds write access. A second run changes nothing.
- The key file is outside `.verify/state`, so `machine.sh down` keeps it. A new machine reuses the key that GitHub already has.
- `matrix.py run` stops before the first cell when the key file is missing.
- Adding the key needs a `gh` login with admin rights on the repository.

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

Run it first, and again whenever something looks wrong. It changes nothing on the machine or on GitHub. It checks that:

- the deploy key file exists, and GitHub has the key as a deploy key of the fixture repository. If not, run `matrix.py fixtures key`;
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
.claude/skills/verify-opencloud/scripts/matrix.py run --set full                        # all 55 cells
.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-*' --only 'php-sqlite-*'
```

The runner prints one `PASS`, `FAIL`, or `SKIP` line per check and a summary table. It exits 0 only when every cell passes. A `SKIP` does not fail a cell. Cells run one after another, because the dashboard builds one image at a time.

The runner posts JSON to the same routes as the dashboard forms, with the session cookie and the `X-XSRF-TOKEN` header of a browser:

- `POST /applications` is the create form. It sets only the repository, the branch, the name, the key, the cluster, the type, and the port.
- `DELETE /processes/{id}` removes a process of the preset from the canvas.
- `PATCH /environments/{id}/settings` saves the build command and the deploy commands.
- `DELETE /datastore-attachments/{id}` removes the database of the preset when the cell wants another one.
- `POST /environments/{id}/env` is Settings, then Environment values.
- `POST /environments/{id}/datastores` with `{"kind": "valkey"}` is Add resource. A database can have a connection name, for example `{"kind": "postgres", "name": "reports"}`.
- `POST /environments/{id}/deployments` is the Deploy button. The first deploy starts with it too.

It reads the page props from the `data-page` attribute of `GET /environments/{id}`, `/deployments/{id}`, and `/applications/create`. It checks the sites with HTTPS requests to the free address. The test machines use Caddy's local CA, so the runner does not check the certificate chain.

To add a cell, add a value to the `databases` or `caches` of a fixture in `matrix.json`. A new value also needs code in `Cell` in `matrix.py`. `list` stops when a set names a cell that no fixture supports. To add a fixture, add a folder in `fixtures/` and an entry in `fixtures` of `matrix.json`, prove it with `matrix.py local`, and run `matrix.py fixtures sync`. A fixture must answer `/` with a `<title>`. Its verify endpoint must return JSON with the values of its `checks`: `marker`, `build`, `secret_sha`, `db`, `cache`, and `extra`. `db` has `driver` (`mysql`, `pgsql`, or `sqlite`), `ok`, `hits`, and `deploys`. Each deploy command must write a row to `verify_deploys`. `extra` has one entry for each `<NAME>_DB_URL` or `<NAME>_DATABASE_URL` value, keyed by the name in lowercase, with `driver`, `ok`, and `error` when the connect fails.

## Local run

```bash
.claude/skills/verify-opencloud/scripts/matrix.py local --set full --only 'node-*'
```

`local` proves a fixture on this computer before a matrix run. It needs Docker and PHP, and no machine. For each cell, it:

- asks the dashboard code of this checkout, through `scripts/local-plan.php`, for the Dockerfile that the preset writes, the key of the build values, the port, and the env values of the preset and of each attachment;
- builds the image with that Dockerfile and the build env value `VERIFY_BUILD`;
- starts MySQL, Postgres, and Valkey at the versions of `DatastoreManifests::VERSIONS`, with a password that holds URL characters;
- runs the deploy commands three times, starts the web container, and runs the checks of steps 2 and 3;
- removes its containers, its network, and its image.

The evidence goes to `.verify/runs/<YYYYMMDD-HHMMSS>-local/<cell>/`, with `build.log`, `deployment-N.log`, `web.log`, and the step files. A local run proves the fixture and the Dockerfile of the preset. It does not prove the cluster, Caddy, the dashboard forms, or a redeploy under traffic. A fixture whose preset is not in the checkout fails at `local-plan.php`.

## Evidence

Each run writes to `.verify/runs/<YYYYMMDD-HHMMSS>/`, which git ignores:

- `summary.md` holds one row per cell: the verdict, the seconds, the first deploy time, the number of skipped checks, and the failed checks.
- `machine.json` holds the machine, `BASE`, `BUILT_FROM`, the `HEAD` at the run, and the cells.
- `<cell>/` holds:
  - `config.json`, the body of the create form plus the settings, the database, and the env values that the runner configures, with the secret value as `<secret>` and the deploy key as `<key>`;
  - `deployment-1.log` to `deployment-3.log`, the deploy logs;
  - `step-N-page.html` and `step-N-verify.json`, the live responses of each step;
  - `step-3-requests.json`, the request count and the failures during the third deploy;
  - `canvas.json`, the environment page props at the end;
  - `result.json`, each check with its verdict and detail, and the names of the skipped checks.

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
- The runner sends every request through `curl`. macOS Local Network privacy can block the sockets of Python, including Homebrew's and Apple's, to the OrbStack address with "No route to host". It allows `curl`, which is a system binary. Do not change the runner back to `urllib`. If a quick check from Python fails with that error, use `curl` instead.
- A second run on the same machine reuses the app names. The free address then gets the app id in its name. The runner reads the address from the page props, so this is harmless.
- A Dockerfile app and a static site ignore the build command. The Laravel, Node.js, Python, and Ruby templates run it. A Dockerfile fixture reads a build env value with `RUN --mount=type=secret,id=<KEY>`. The Node.js and static templates put each build env value in the env of the build script, so `node` and `site` read `process.env.VERIFY_BUILD`.
- The `type` of a fixture in `matrix.json` decides its env names. The user sets no env names. A fixture that reads the wrong names reports no database and no cache.
- A static site has no server process. It cannot read a runtime env value, a secret, or a datastore, so `site` skips those checks. Its verify endpoint is the file `/verify.json`, because the static preset answers each other path with `index.html`.
- The `python` and `ruby` cells need the Python and Ruby presets. The Ruby preset must have a C compiler and the MySQL client headers, because `puma`, `nio4r`, and `mysql2` compile. `pg` installs as a binary gem.
- The first build of each fixture on a new machine downloads every package and can take several minutes. Later builds use the BuildKit cache and take seconds.
- `matrix.py fixtures sync` pushes to `samirnijenhuis/opencloud-verify-fixtures` with the `gh` login. The repository is private, so `fixtures sync`, `fixtures check`, and `doctor` also read it with the `gh` login. The cells read it with the deploy key. Someone without access to the repository cannot run the matrix.

## Feature map

`features/README.md` lists the user-facing features that this skill verifies, with the routes, the proof, and the gotchas of each. Read the matching file before you change a check or verify one feature alone.

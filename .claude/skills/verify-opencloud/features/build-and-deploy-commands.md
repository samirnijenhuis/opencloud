# Build and deploy commands

A user gives a build command that runs in the image build and deploy commands that run in a one-off pod before the new version gets traffic.

## Sub-features

- `build-laravel` runs the build command as a `RUN` line in the final stage of the Laravel template.
- `build-dockerfile` builds the Dockerfile of the branch, which ignores the build command.
- `build-node` installs the packages with the package manager of the lock file (`npm ci` for `package-lock.json`), then runs the build script, then the build command.
- `build-static` runs the build script of a static site in a Node stage and serves the first output folder (`dist`, `build`, `out`, `_site`, or `public`). It ignores the build command.
- `build-python-ruby` builds a Python or Ruby app with its locked packages, runs the build command, and starts the first `web:` line of the `Procfile`.
- `deploy-commands` run each deploy command with the new image and the env values before the rollout.
- `deploy-log` shows the output of the build and the deploy commands in the deployment log.

## How to get to it (user POV)

- Fill in `Build command` and `Deploy commands` in the create form.
- Choose `Settings`, go to the `Deployments` section, change the commands, and choose `Save`. Then choose `Deploy`.
- Open a deployment from the `Deployments` tab to read its log.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Laravel build command.** Deploy a Laravel cell. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-none'`. The output shows `PASS step 1: the build saw its env value or command (laravel-mysql-none)`, which the build command wrote to `storage/app/build-marker`.
- **SQLite build command.** Deploy a Laravel SQLite cell. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-sqlite-none'`. The build command migrates a SQLite file into the image, and the output shows `PASS step 1: the sqlite database answers`.
- **Presets with no Dockerfile.** Deploy the Node.js, static site, Python, and Ruby apps. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'node-none-none' --only 'site-none-none' --only 'python-none-none' --only 'ruby-none-none'`. Each cell shows `PASS step 1: the build saw its env value or command (<cell>)`. The Node.js and static cells prove the build script. The Python and Ruby cells prove the build command.
- **Deploy commands.** Deploy any cell. The output shows `PASS deployment N ran the deploy commands` for each deployment. For the MySQL and Postgres cells of `laravel`, `node`, `python`, and `ruby`, it also shows `PASS step N: each deploy command wrote a row` from step 1. The `php` cells show it from step 2, because they get the database at step 2. The `static` cells show `SKIP`.
- **Proof.** Open `deployment-1.log` of the cell. Its step `Running deploy commands` holds the command and, in a later line, `deploy-marker-<cell>`. A Laravel cell with a database also shows the output of `php artisan migrate --force`.

## Gotchas

- A Dockerfile app and a static site ignore the build command. The Laravel, Node.js, Python, and Ruby templates run it.
- The deploy log shows each deploy command before its output. The `static` and `site` deploy command is `echo deploy-marker-'<cell>'`. The quotes keep the marker out of the command line, so only the output of the command can pass the marker check. Do not remove the quotes.
- A deploy command runs in its own pod. A file that it writes never reaches the web pods, so a SQLite file that a deploy command migrates is lost.
- A failing deploy command fails the deployment and keeps the old version live. `tests/e2e/release-test.sh` proves that case, and this matrix does not.

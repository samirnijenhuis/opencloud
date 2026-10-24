# Build and deploy commands

A user gives a build command that runs in the image build and deploy commands that run in a one-off pod before the new version gets traffic.

## Sub-features

- `build-laravel` runs the build command as a `RUN` line in the final stage of the Laravel template.
- `build-dockerfile` builds the Dockerfile of the branch, which ignores the build command.
- `deploy-commands` run each deploy command with the new image and the env values before the rollout.
- `deploy-log` shows the output of the build and the deploy commands in the deployment log.

## How to get to it (user POV)

- Fill in `Build command` and `Deploy commands` in the create form.
- Choose `Settings`, then `Deployments`, and change the commands. Then choose `Deploy`.
- Open a deployment from the `Deployments` tab to read its log.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Laravel build command.** Deploy a Laravel cell. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-none'`. The output shows `PASS step 1: the build saw its env value or command (laravel-mysql-none)`, which the build command wrote to `storage/app/build-marker`.
- **SQLite build command.** Deploy a Laravel SQLite cell. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-sqlite-none'`. The build command migrates a SQLite file into the image, and the output shows `PASS step 1: the sqlite database answers`.
- **Deploy commands.** Deploy any cell. The output shows `PASS deployment 1 ran the deploy commands` for each deployment. For MySQL and Postgres cells, it also shows `PASS step N: each deploy command wrote a row`.
- **Proof.** Open `deployment-1.log` of the cell. It holds `deploy-marker-<cell>`, and for Laravel the output of `php artisan migrate --force`.

## Gotchas

- A Dockerfile app ignores the build command. Only the Laravel template runs it.
- A deploy command runs in its own pod. A file that it writes never reaches the web pods, so a SQLite file that a deploy command migrates is lost.
- A failing deploy command fails the deployment and keeps the old version live. `tests/e2e/release-test.sh` proves that case, and this matrix does not.

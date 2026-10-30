# Opencloud deploy verification map

This directory is the maintained source for verifying what an opencloud user gets from a deploy. Read the index before driving the machine, then use the matching feature file as the recipe.

## Baseline preconditions

- Run every command from the repository root.
- Start the machine with `.claude/skills/verify-opencloud/scripts/machine.sh up`. The default machine is `oc-verify`. Never use the machine `opencloud`, which holds the user's own apps.
- Run `.claude/skills/verify-opencloud/scripts/matrix.py doctor` and require `OK` on every line.
- Require that `matrix.py fixtures check` reports every fixture branch up to date. If not, run `matrix.py fixtures sync`.
- Never drive a machine that this verification run did not start or update.

## Driving conventions

- Drive the dashboard through `matrix.py`. It posts to the same routes as the dashboard forms, with a browser session and its XSRF token.
- Select cells with `--set full --only '<glob>'`. Each cell id is `<fixture>-<database>-<cache>`.
- Treat every command as literal. Keep the quoted globs unchanged.
- Read the evidence from `.verify/runs/<run id>/<cell>/`. Do not delete it during cleanup.

## Proof and skip reporting

- Proof is the live HTTPS response of the deployed site after the user action, in `step-N-page.html` and `step-N-verify.json`. A green deployment status alone is not proof.
- Each deploy log must hold `deploy-marker-<cell>`.
- Each mutation needs a second view. For example, the database rows of step 1 must still be in the step 2 response.
- Record the cell id and the run id with every claim.
- Report an unreachable cell with the failed check and its evidence file. Do not report it as verified through another cell.
- A `SKIP` line marks a check that the fixture does not report. Report a skipped check as not verified, never as a pass.

## Feature entry contract

Each feature file starts with an H1 title and one paragraph describing the user-visible behavior. It then uses exactly four H2 sections in this order.

1. `Sub-features` lists short IDs with one line for each behavior.
2. `How to get to it (user POV)` lists every user entry point.
3. `Driving it with matrix.py` starts with `Preconditions:` and uses labeled bullets that pair each user action with an exact command and observable result.
4. `Gotchas` lists traps that can waste or invalidate a verification run.

## Features

- [Create an application](./create-application.md) covers the create form, the presets, the configuration before the first deploy, the first deploy, and the free HTTPS address.
- [Datastores](./datastores.md) covers MySQL, Postgres, SQLite, Valkey, a second SQL database with a connection name, and the Laravel and generic env names.
- [Environment values](./env-values.md) covers runtime values, build values, and write-only secrets.
- [Build and deploy commands](./build-and-deploy-commands.md) covers the build of each preset, the build command, and deploy commands.
- [Redeploy](./redeploy.md) covers a redeploy after a change, data that stays, and a redeploy with no failed request.

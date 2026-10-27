# Redeploy

A user deploys again after a change, or without one, and the site keeps answering while the new version starts. Database rows stay.

## Sub-features

- `redeploy-change` applies changed env values and new datastores.
- `redeploy-data` keeps the MySQL and Postgres rows of earlier deploys.
- `redeploy-traffic` serves every request during the rollout.

## How to get to it (user POV)

- Choose `Deploy` in the environment header.
- The button posts to `POST /environments/<id>/deployments` and goes to `/deployments/<id>`.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Change and redeploy.** Change a value, add Valkey, and deploy. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'php-postgres-valkey'`. The output shows `PASS deployment 2 goes live` and `PASS step 2: the redis cache works`.
- **Data stays.** Compare the rows before and after the redeploy. The output shows `PASS step 2: the rows of earlier steps are still there (<new count> > <old count>)`.
- **Traffic.** Deploy while the runner polls the site four times a second. The output shows `PASS the redeploy under traffic fails no request (0 of <n> failed)`.
- **Proof.** Open `step-3-requests.json` of the cell. `failed` is `0`, and `failures` is empty.

## Gotchas

- A SQLite database starts empty after each redeploy. The matrix checks no rows across deploys for SQLite cells.
- The `site` cell has no database and no runtime env value. Its redeploy proves only that the site goes live again and serves every request.
- Every request to a verify endpoint with a database writes a row. The row count grows during the third deploy, and that is expected.
- The traffic check needs at least one request. A deploy that ends in a few seconds still sends about a dozen.

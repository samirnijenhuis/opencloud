# Datastores

A user attaches a database or a cache to an environment, and the app gets the connection values as env values on the next deploy.

## Sub-features

- `datastore-mysql` attaches MySQL 8.4 from the create form and sets `DB_CONNECTION=mysql` and the other `DB_*` values.
- `datastore-postgres` attaches Postgres 17 from the create form and sets `DB_CONNECTION=pgsql`.
- `datastore-sqlite` uses a SQLite file in the image or in the pod, with no attachment.
- `datastore-valkey` attaches Valkey with Add resource and sets the `REDIS_*` values.
- `datastore-one-sql` refuses a second SQL database on one environment.

## How to get to it (user POV)

- Choose the `Database` option in the create form. The form offers it for a Laravel app only.
- On the `Environment` tab, choose `Add resource`, then a kind. Then choose `Deploy`.
- Add resource posts to `POST /environments/<id>/datastores` with `{"kind": "<kind>"}`.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **MySQL and Postgres.** Deploy apps that use each server. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'php-mysql-none' --only 'laravel-postgres-none'`. The Laravel cell shows `PASS step 1: the pgsql database answers`. The PHP cell shows `PASS Add resource attaches mysql` and `PASS step 2: the mysql database answers`.
- **SQLite.** Deploy apps that use a SQLite file. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only '*-sqlite-none'`. The PHP and Laravel cells show `PASS step 1: the sqlite database answers`.
- **Valkey.** Add Valkey after the first deploy. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-valkey'`. The output shows `PASS valkey becomes ready` and `PASS step 2: the redis cache works`.
- **One SQL database.** Add Postgres to an app that has MySQL. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysqlpostgres-none'`. The output shows `PASS a second SQL database is refused (This environment already has a database. Detach it first.)`, and the app keeps working on MySQL.
- **Proof.** Open `step-2-verify.json` of the cell. `db.driver` and `cache.driver` name the attached datastores, and `ok` is `true`.

## Gotchas

- A Dockerfile app gets its MySQL or Postgres database with Add resource, so the matrix adds it before the second deploy.
- Valkey values reach the app only after the next deploy. Wait for `ready` before you choose Deploy.
- Each SQLite pod has its own copy of the file, and a redeploy starts empty. The matrix checks no persistence for SQLite.
- The static fixture cannot use a database. Its database cells prove only that an attached datastore does not break the deploy.
- A Laravel app with no database needs `SESSION_DRIVER=cookie` and `CACHE_STORE=file`, because the defaults use the database. The matrix sets them for the `none` cells.

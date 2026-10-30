# Datastores

A user attaches a database or a cache to an environment, and the app gets the connection values as env values on the next deploy.

## Sub-features

- `datastore-env-names` gives the env names of the preset. A Laravel app gets the Laravel env names. A Node.js, Python, Ruby, or static app gets the generic env names. A Dockerfile app gets both sets for the same resource.
- `datastore-mysql` attaches MySQL 8.4 from the create form or with Add resource. With the Laravel env names it sets `DB_CONNECTION=mysql` and the other `DB_*` values. With the generic env names it sets `DATABASE_URL=mysql://...`.
- `datastore-postgres` attaches Postgres 17. With the Laravel env names it sets `DB_CONNECTION=pgsql`. With the generic env names it sets `DATABASE_URL=postgresql://...`.
- `datastore-sqlite` uses a SQLite file in the image or in the pod, with no attachment.
- `datastore-valkey` attaches Valkey with Add resource. With the Laravel env names it sets the `REDIS_*` values, `REDIS_CLIENT=phpredis`, and `CACHE_STORE=redis`. With the generic env names it sets `REDIS_URL`.
- `datastore-named-sql` adds a second SQL database with a connection name. The default database keeps its values. The other database sets a secret value, `<NAME>_DB_URL` with the Laravel env names or `<NAME>_DATABASE_URL` with the generic env names, and the app reaches it with that URL. A Dockerfile app gets both values.

## How to get to it (user POV)

- Choose the `Database` option in the create form. The form offers it for each preset.
- On the `Environment` tab, choose `Add resource`, then a kind. Then choose `Deploy`.
- Add resource posts to `POST /environments/<id>/datastores` with `{"kind": "<kind>"}`. For MySQL or Postgres, the form asks for a connection name and posts it as `name`. The first database of an environment is the default. Its name is `main` when the form gives no name.
- On the sheet of a database, `Make default` posts to `POST /datastore-attachments/<id>/default`. The keys change on the next deploy.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **MySQL and Postgres.** Deploy apps that use each server. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'php-mysql-none' --only 'laravel-postgres-none'`. The Laravel cell shows `PASS step 1: the pgsql database answers`. The PHP cell shows `PASS Add resource attaches mysql` and `PASS step 2: the mysql database answers`.
- **SQLite.** Deploy apps that use a SQLite file. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only '*-sqlite-none'`. The PHP and Laravel cells show `PASS step 1: the sqlite database answers`.
- **Valkey.** Add Valkey after the first deploy. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'laravel-mysql-valkey'`. The output shows `PASS valkey becomes ready` and `PASS step 2: the redis cache works`.
- **The generic env names.** Deploy the Node.js, Python, and Ruby apps with each server. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'node-postgres-valkey' --only 'python-mysql-valkey' --only 'ruby-postgres-none'`. The output shows `PASS step 1: the pgsql database answers` or `PASS step 1: the mysql database answers`, and `PASS step 2: the redis cache works` for the Valkey cells. These apps read only `DATABASE_URL` and `REDIS_URL`.
- **A second SQL database.** Add Postgres named `reports` to an app that has MySQL. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only '*-mysqlpostgres-none'`. The output shows `PASS Add resource attaches postgres reports`, `PASS postgres reports becomes ready`, and `PASS step 2: the pgsql database answers through REPORTS_DB_URL`. A generic cell shows `REPORTS_DATABASE_URL`. The app keeps working on MySQL through its default values.
- **Proof.** Open `step-2-verify.json` of the cell. `db.driver` and `cache.driver` name the attached datastores, and `db.ok` and `cache.ok` are `true`. For a `mysqlpostgres` cell, `extra.reports.driver` is `pgsql` and `extra.reports.ok` is `true`.

## Gotchas

- The `static` and `php` cells get their MySQL or Postgres database with Add resource before the second deploy. The other cells get it from the create form. So the matrix proves both paths.
- Valkey values reach the app only on the next deploy. The deploy waits up to 10 minutes for each attachment to be `ready`, and it fails when an attachment fails. The runner waits for `ready` before it chooses Deploy.
- The matrix posts `CACHE_STORE=redis` as a user value with Valkey, because a `none` Laravel cell has the user value `CACHE_STORE=file` from step 1. A user value wins, so the matrix does not prove that the Laravel env names set `CACHE_STORE`.
- Each SQLite pod has its own copy of the file, and a redeploy starts empty. The matrix checks no persistence for SQLite.
- The `static` fixture cannot use a database. Its database cells prove only that an attached datastore does not break the deploy. The runner prints `SKIP` for its database, `extra`, and cache checks. The `site` fixture has no database cells.
- A local run uses a password with `:`, `@`, `/`, and `+` for each datastore, so a fixture must decode the user and the password of each URL.
- `REPORTS_DB_URL` reaches the app only on the next deploy, like each env value. The Laravel fixture reads the `*_DB_URL` values in `config/verify.php`, because the app caches its config at start.
- A name must not be a Laravel stock connection, such as `mysql` or `pgsql`. The dashboard refuses it.
- A Laravel app with no database needs `SESSION_DRIVER=cookie` and `CACHE_STORE=file`, because the defaults use the database. The matrix sets them for the `none` cells.

---
paths:
  - "dashboard/database/**"
  - "dashboard/app/Domains/*/Models/**"
  - "dashboard/config/database.php"
---

# Database and migrations

## The dashboard database

- The dashboard database is one SQLite file, `/var/lib/opencloud/data/database.sqlite`. The `web`, `worker`, `builder`, and `scheduler` containers share it. The tests use SQLite in memory.
- The queue, the cache, and the sessions use the `database` driver, so they live in the same file.
- The connection uses WAL, a 10 second `busy_timeout`, and `transaction_mode` `IMMEDIATE` (`config/database.php`). A transaction takes the write lock at its start, and the other containers wait for it. Keep each transaction short, and keep external calls out of it.
- Use a transaction only for an invariant across several writes. `SetDefaultDatabase` writes the env values of the new default and of the old default in one transaction.
- Write each query and each migration so that it runs on SQLite.

## Columns

- Store an enum as a string column, and cast it to a backed enum on the model. Do not use a database enum column.
- Store a secret with the `encrypted` cast, and list the column in `$hidden`. Add the column to `DataModelTest::encryptedFields()`. The `APP_KEY` encrypts the value, so a new key cannot read it.
- Without `$hidden`, `toArray()` and `toJson()` send the decrypted value, also into Inertia props.
- Point at another table with `foreignId()->constrained()` and an explicit `cascadeOnDelete()` or `nullOnDelete()`. Name the reason for the choice in the commit message.
- Index each column that a query filters or joins on.
- Enforce a rule of "at most one" with a partial unique index, for example `deployments_one_live_per_environment` and `datastore_attachments_one_default`. The schema builder cannot make a partial index, so write it with `DB::statement('CREATE UNIQUE INDEX ... WHERE ...')`.
- A morph column stores the morph alias, never a class name. `audit_events.subject_type` holds `environment`, not `App\Domains\Environments\Models\Environment`.
- Do not put a length limit on a status column.

## Models

- A model holds its relations, its casts, `$fillable`, `$hidden`, the `#[UseFactory]` and `#[UsePolicy]` attributes, and small query helpers.
- List the columns that code may fill in `$fillable`. Keep `role` out of `User::$fillable`, because code sets the role and request input never does.
- Do not add a property that is not a column. Compute the value where you render it.
- Do not use `$with`, accessors, mutators, or model events. Read `.claude/rules/architecture.md`.
- A model can hold one rule set that its Actions and Form Requests share, as `Process::rules()` does.
- Move a status through its state machine: `DeploymentStatus::canMoveTo()` and `CommandRunStatus` list the allowed moves.

## Migrations

- A migration changes the schema. The web container runs `php artisan migrate --force` at each start. The other containers wait until no migration is pending.
- An upgrade copies the database before the new release starts, and a failed upgrade puts the copy back. Write `down()` for development and for the migration test, not for production.
- When a schema change must move data, move the data in the same migration. Use `DB::table()`, table names, and string values. Never use a model class or an enum in a migration, because the class can change later.
- Insert rows in chunks. `2026_10_24_100002_move_worker_and_scheduler_into_processes.php` is the example.
- Give a migration that moves data a test in `tests/Database/Migrations`. Read `.claude/rules/testing.md`.
- When you drop a model, keep the columns of other tables that point at its table. Drop them only when the task says so.
- Only one dashboard runs at a time: `platform/dashboard.yaml` has one replica and the `Recreate` strategy. A migration does not need a step for an old dashboard that still reads the old shape.

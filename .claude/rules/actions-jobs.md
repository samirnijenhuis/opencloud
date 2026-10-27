---
paths:
  - "dashboard/app/Domains/**"
  - "dashboard/app/Http/Controllers/**"
  - "dashboard/app/Console/**"
  - "dashboard/routes/console.php"
---

# Actions, jobs, and dependencies

## Where a change happens

- An Action or a queued Job makes each change to the database. A controller, a command, a scheduler closure, a model, a service, a provisioner, or an observer writes no record.
- A controller calls one Action for each change. Inject the Action as a parameter of the controller method, not in the constructor.
- A command calls an Action or dispatches a Job.
- Opencloud has no domain events and no listeners. Do the follow-up work of a change in the Action, or in a Job that the Action dispatches. Do not use model events or observers.
- Put a side effect in a Job when it is slow, calls an external system, or waits for an outside state. Keep a fast database change in the Action.

## Actions

- An Action has one public method, `execute()`. Only an Action has an `execute()` method. Another class names its method after what it returns: `CaddyConfig::forCluster()`, `EnvironmentVariables::runtime()`, `LiveStatus::environment()`.
- The constructor takes only dependencies, as object types. `execute()` takes the values of one run as explicit typed parameters.
- Do not give a new Action a `$data` or `$settings` array. `CreateApplication`, `CreateCluster`, `ConnectCluster`, `UpdateEnvironmentSettings`, `UpdatePreviewSettings`, `AcceptInvite`, and `CreateOwner` still take an array. Do not copy them.
- Put the subject model first, the acting user `$by` second, and the other values after them: `AddDomain::execute(Environment $environment, User $by, string $hostname, bool $manageDns = false)`.
- Type the user as `?User $by` when opencloud itself can act. A null user means opencloud, for example after an upgrade, for a deploy hook, or for a pull request.
- When an Action needs a second public entry point, move that logic to a service class that the Action injects.
- Return the record that the Action made or changed, or nothing.
- An Action that writes a record or dispatches a Job calls `AuditEvent::record($by, $action, $subject, $context)`. An Action that only reads records nothing, for example `ReadLogs`, which the UI polls.
- Refuse a request that the user can fix with `ValidationException::withMessages(['field' => 'Message.'])`. Laravel shows the message on the form, so the controller needs no `try` or `catch`.
- Dispatch each Job from an Action with `->afterCommit()`. A caller can run the Action inside a transaction, as `CreateApplication` runs `AttachDatastore` and `AddDomain` inside `DB::transaction()`.
- Keep `kubectl`, `tofu`, `git`, and HTTP calls out of a transaction. The SQLite connection uses `transaction_mode` `IMMEDIATE`, so a transaction holds the write lock from its start. The other dashboard containers wait for that lock. `CreateApplication` calls `Git::head()` before its transaction opens.

## Audit events

- Name the audit action `<subject>.<what happened>` in snake_case: `deployment.started`, `domain.added`, `datastore.default_changed`, `cluster.destroy_requested`.
- Put identifiers and values in the context: `['environment_id' => $environment->id, 'hostname' => $hostname]`. For an update, record only the fields that changed, as `UpdateProcess` does.
- Never put a secret in the context. `AuditEvent::record()` throws for the keys `value`, `secret`, and `password` at any depth. Mask other text that can hold a secret, as `RunCommand` masks the command.

## Dependencies

Inject each dependency in the constructor. Code in `app/Domains` calls no facade, no `app()`, and no `resolve()`. Inject these classes in place of the facades:

| Facade | Inject |
|---|---|
| `DB` | `Illuminate\Database\ConnectionInterface` |
| `Http` | `Illuminate\Http\Client\Factory` |
| `Process` | `Illuminate\Process\Factory` |
| `Log` | `Psr\Log\LoggerInterface` |
| `Cache` | `Illuminate\Contracts\Cache\Repository` |
| `File` | `Illuminate\Filesystem\Filesystem` |
| `Storage` | `Illuminate\Contracts\Filesystem\Factory` |
| `Artisan` | `Illuminate\Contracts\Console\Kernel` |
| `Crypt` | `Illuminate\Contracts\Encryption\Encrypter` |
| `URL` | `Illuminate\Contracts\Routing\UrlGenerator` |
| `Validator` | `Illuminate\Contracts\Validation\Factory` |

- `Http::fake()` and `Process::fake()` put the fake into the container. A class that the test resolves after the fake call gets the fake through injection.
- A static method of an opencloud class is not a facade. `AuditEvent::record()`, `Kubectl::for()`, `Provisioners::of()`, `Git::head()`, and `RunDeployment::dispatch()` stay as they are.

## Jobs

- A Job implements `ShouldQueue`. Only a Job implements `ShouldQueue`. Do synchronous work in the Action itself.
- Give the Job its Eloquent models, not their ids: `public function __construct(public Deployment $deployment) {}`. `Queueable` stores the model key and loads the model again when the job runs.
- Set `public bool $deleteWhenMissingModels = true` when the record can go away before the job runs.
- The workers run with `--tries=1`. A Job that calls `$this->release()` sets `$tries` or `retryUntil()`, or the worker fails it at the next attempt. `CheckDomain` sets `$tries`, and `ExecuteCommandRun` sets `retryUntil()`.
- Retry only work that is safe to run again against an external system. `SyncCaddy` sets `$tries = 5` and a `$backoff` list.
- Use `WithoutOverlapping` with a key for each record when two runs for one record must not overlap. With `dontRelease()`, the middleware drops the second run. With `releaseAfter()`, the middleware puts it back on the queue for later. `ProvisionCluster` uses `tofu-cluster-<id>` with `dontRelease()`, and `RunDeployment` uses `deploy-environment-<id>` with `releaseAfter(15)`.
- Use `ShouldBeUniqueUntilProcessing` with `uniqueId()` and `$uniqueFor` when one waiting job covers each later dispatch. `SyncCaddy` is unique for each cluster.
- Make a Job converge. It reaches the same end state when it runs twice, and after a crash at any step. `RestoreInstall` records each step in `restore.json` and skips each step that an earlier run finished.

## Queues

- The dashboard pod runs two queue workers (`dashboard/docker/entrypoint.sh`). The `worker` container serves `default` with a 600 second timeout. The `builder` container serves `builds` with a 3600 second timeout.
- Put a Job that can run for minutes on `builds`. Examples are a build, a dump, a restore, and an OpenTofu run. Set the queue in the constructor with `$this->onQueue('builds')`, so each dispatch uses it.
- Keep a short Job on `default`: `SyncCaddy`, `CheckDomain`, `ProvisionDatastoreAttachment`, `DeployPush`, `SyncPreview`, `PostPreviewComment`, and `ExecuteCommandRun`.
- Never put datastore provisioning on `builds`. A deployment on `builds` waits up to 10 minutes for its datastores. Provisioning in the same queue would wait behind it, and the deployment would fail. `LongJobQueueTest` holds this rule.
- A Job that waits for an outside state checks once and releases itself. `ExecuteCommandRun` checks the Kubernetes Job and releases itself for 3 seconds, so a command of 15 minutes never holds the worker.
- Keep the `$timeout` of a Job below the `retry_after` of the `database` queue, 3900 seconds in `config/queue.php`. After `retry_after`, the queue gives the job to a worker again.
- A new queue name needs a worker. Read `.claude/rules/registration-checklists.md`.

## Commands and the scheduler

- Put a command in `app/Console/Commands` and name it by its verb phrase: `CopyDatabase`.
- Refer to a command by its class in PHP, also a framework command: `RestartCommand::class` for `queue:restart`. Never use the signature string.
- Schedule recurring work in `dashboard/routes/console.php`. Prefer `Schedule::job(new X)` with a frequency. Give a `Schedule::call()` closure a `->name()`, as `sync-caddy` and `nightly-backups` have.
- A scheduler closure only finds records and dispatches jobs. It writes no record.

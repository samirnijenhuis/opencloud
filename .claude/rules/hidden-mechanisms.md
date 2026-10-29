# Hidden mechanisms

This file lists behavior that you cannot see at the call site. Check it before you assume that code is local. The paths are relative to `dashboard/`. The line numbers are from the commit that wrote this file, so search for the name when a line moved.

## On each boot and each start

- `AppServiceProvider::boot()` calls `DashboardDomain::applyRootUrl()` (`app/Providers/AppServiceProvider.php:42`). With a verified dashboard domain, it sets `app.url`, forces the root URL, and forces `https` (`app/Domains/Routing/Models/DashboardDomain.php:70-86`).
- `applyRootUrl()` reads the `dashboard_domains` table on each boot. It ignores a `QueryException`, so the app boots before the first migration.
- A queue worker reads the root URL once, when it boots. So each change of the dashboard domain runs `queue:restart` (`app/Domains/Routing/Jobs/CheckDashboardDomain.php:41`, `app/Domains/Routing/Actions/RemoveDashboardDomain.php:25`, `app/Domains/Restore/RestoreInstall.php:304`).
- At each start, the `web` container runs `php artisan migrate --force`, then `opencloud:bootstrap` when that command exists, then `config:cache`, `route:cache`, and `view:cache` (`docker/entrypoint.sh:17-27`).
- The `worker`, `builder`, and `scheduler` containers wait until no migration is pending (`docker/entrypoint.sh:4-10`).
- `opencloud:bootstrap` creates or updates the `local` cluster. It also runs `ReinstallClusterParts` for each other cluster with parts of an older release (`app/Console/Commands/Bootstrap.php:22-40`).

## Model hooks

- `AppServiceProvider::boot()` registers `DeploymentObserver` on `Deployment` (`app/Providers/AppServiceProvider.php:43`).
- `DeploymentObserver::updated()` queues `PostPreviewComment` when the status of a preview deployment changes to `live` (`app/Domains/Previews/Observers/DeploymentObserver.php:12-16`). A save through the model starts it. An update through the query builder does not.
- `Environment::booted()` sets `color` on create from `EnvironmentColor::defaultFor($kind)` when the caller gives no color (`app/Domains/Environments/Models/Environment.php:61-66`).
- `Deployment::moveTo()` saves the new status only when `DeploymentStatus::canMoveTo()` allows the move, and else throws a `LogicException` (`app/Domains/Deployments/Models/Deployment.php:36-44`). A direct write to `status` skips that check.

## Kubernetes, processes, and the fakes

- `Kubectl::for()` gets `KubectlFactory` from the container (`app/Support/Kube/Kubectl.php:13-16`). `FakeKubectl::install()` binds itself in its place (`app/Support/Kube/FakeKubectl.php:21-27`), so each later `Kubectl::for()` in the test returns the fake.
- `Kubectl` runs `kubectl` with Symfony Process (`app/Support/Kube/Kubectl.php:136-138`). `Process::fake()` does not catch a `kubectl` call.
- `RunDeployment` deletes each Deployment, CronJob, and HorizontalPodAutoscaler in the environment namespace with an `opencloud.dev/process` label that no process of the environment uses as that kind (`EnvironmentManifests::strays()`). A Deployment, a CronJob, or an autoscaler that you add by hand with that label goes away on the next deploy.
- `StartDeployment` calls `WakeEnvironment` (`app/Domains/Deployments/Actions/StartDeployment.php`). A deploy of a sleeping environment wakes it at the start.
- `ScaleEnvironment` deletes the autoscalers, scales each Deployment of the environment to 0, and suspends its CronJobs while the environment sleeps (`app/Domains/Environments/Jobs/ScaleEnvironment.php`). It scales a standard Valkey of the environment in `oc-datastores` too. A Deployment that you scale by hand goes back to its minimum on the next wake.
- After a deployment goes live, `RunDeployment::pruneEnvSecrets()` keeps the live env Secret and the two newest, and deletes the others (`app/Domains/Deployments/Jobs/RunDeployment.php:272-289`).

## Secrets and the audit log

- `AuditEvent::record()` throws an `InvalidArgumentException` when the context has the key `value`, `secret`, or `password`, in any case and at any depth (`app/Domains/Audit/Models/AuditEvent.php:21`, `:37-61`).
- Masking is not global. Each writer masks its own text: `DeploymentLog::write()` (`app/Domains/Deployments/DeploymentLog.php:36`), `TofuLog` (`app/Support/Tofu/TofuLog.php:21`), `RunCommand` for the audit context (`app/Domains/Environments/Actions/RunCommand.php:39`), and `ExecuteCommandRun` for the output (`app/Domains/Environments/Jobs/ExecuteCommandRun.php:157`). New text that can hold a secret needs its own masker.
- `SecretMasker` never masks a value shorter than 4 characters (`app/Support/SecretMasker.php:13`).
- `SecretMasker::forEnvironment()` reads the secret env values and the attachment passwords when you build it (`app/Support/SecretMasker.php:41-50`). A secret that the code adds later is not in that masker.

## Queues and the scheduler

- The workers run with `--tries=1` (`docker/entrypoint.sh:31`, `:35`). A job that throws fails at once, unless it sets `$tries` or `retryUntil()`.
- The `database` queue gives a job to a worker again after `retry_after`, 3900 seconds (`config/queue.php:42`).
- `SyncCaddy` is unique for each cluster until it starts (`app/Domains/Routing/Jobs/SyncCaddy.php:15`, `:25-32`). A dispatch while one waits for the same cluster does nothing. `CheckCertificates` works the same way (`app/Domains/Routing/Jobs/CheckCertificates.php:16`).
- The scheduler in `routes/console.php:24-38` runs these entries:
  - `sync-caddy` dispatches `SyncCaddy` for each ready cluster every five minutes. A change to the Caddy config reaches each ready cluster within five minutes, also without a dispatch.
  - `FailStuckDeployments` runs every minute. It fails a deployment in `building` or `deploying` that had no update for 70 minutes (`app/Domains/Deployments/Jobs/FailStuckDeployments.php:19`, `:24-25`).
  - `CheckCertificates` runs every minute.
  - `CheckLatestRelease` runs once a day.
  - `nightly-backups` runs at 03:00. It dispatches `BackupDatastore` and `CopyBuckets` for each datastore that has the capability, and `BackupDashboard`.

## Requests and pages

- `routes/webhooks.php` loads as the `api` group with an empty prefix (`bootstrap/app.php:13-14`). Its routes have no session, no cookies, and no CSRF check. A webhook route in `routes/web.php` gets a 419.
- `trustProxies(at: '*')` trusts the forwarded scheme, host, and client address from each sender (`bootstrap/app.php:20`). `$request->ip()` returns the forwarded address.
- `dontFlash(['kubeconfig', 'registry_password', 'deploy_private_key'])` keeps those fields out of the session after a failed validation (`bootstrap/app.php:28`). A hand-written `back()->withInput()` flashes every field, so `ApplicationController::store()` leaves out `deploy_private_key` itself.
- `HandleInertiaRequests::share()` sends each page `auth.user`, three `can` flags, and, for a signed-in user, the version and the newer release for the owner (`app/Http/Middleware/HandleInertiaRequests.php:40-62`). `auth.user` is the whole `User` model without its `$hidden` fields.
- `GitHubWebhookController` stores each `X-GitHub-Delivery` id for one day with `Cache::add()` (`app/Http/Controllers/GitHubWebhookController.php:30-34`). A repeated id answers 200 and queues nothing.

## Caches

- `LiveStatus` keeps each answer for 10 seconds, for each environment and each cluster (`app/Domains/Environments/LiveStatus.php:21`, `:39`, `:57`).
- `GitHubClient` keeps the installation token, encrypted, until one minute before it expires (`app/Domains/GitHub/GitHubClient.php:88`).
- `CheckLatestRelease` keeps the tag of the latest release for three days (`app/Domains/Upgrades/Jobs/CheckLatestRelease.php:33`).

## The SQLite database

- `transaction_mode` is `IMMEDIATE` (`config/database.php:44-47`). Each transaction takes the write lock at its start. The other containers wait up to the 10 second `busy_timeout` and then fail.

## Rules inside feature tests

- `ProvisionersTest` also scans the code. It fails in these cases:
  - Code other than the registry creates a local provisioner.
  - Code picks a local provisioner by the kind alone.
  - An Action or a Job branches on the managed source.
  - A class other than the `Local*` provisioners names `Kubectl`.
- `EnvPinTest` compares the Laravel env of each attachment shape with `tests/Fixtures/env/laravel.json`. A change to that file changes the env of every deployed Laravel app.
- `LongJobQueueTest` checks which jobs run on `builds`.

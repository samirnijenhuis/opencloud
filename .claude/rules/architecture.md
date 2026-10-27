# Architecture

`docs/architecture.md` gives the system design: the cluster layout, Caddy, OpenTofu, builds, deployments, datastores, and roles. This file says where code goes in `dashboard/app/` and how the parts may depend on each other. The Architecture suite in `dashboard/tests/Architecture` checks most of these rules.

## The tree

```
dashboard/
  app/
    Console/Commands/   Artisan commands. An entry point.
    Domains/<Domain>/   The business logic, one folder for each area.
    Http/               Controllers, Form Requests, and middleware. An entry point.
    Providers/          Service providers.
    Support/            Gateways and helpers that every domain uses.
  routes/               web.php, settings.php, auth.php, webhooks.php, and console.php (the scheduler).
  database/             Migrations, factories, and seeders.
  resources/js/         The Inertia and React frontend.
  tests/                The tests. The tree mirrors app/.
```

## Entry points and domains

- Opencloud is one app with two entry points. `Http` takes requests from browsers, GitHub, and CI. `Console` holds the Artisan commands, and `routes/console.php` holds the scheduler.
- An entry point reads the input, calls a domain, and answers. It holds no business logic.
- A controller validates in a Form Request, checks the policy there, and calls one Action. Do not validate in the controller.
- A domain groups the business logic of one area. Each domain is a small Laravel app that follows the normal Laravel conventions. Use the framework. Do not work around it.
- A domain never uses a class from `App\Http` or `App\Console`. The controller or the command passes plain values into the domain.
- A domain never reads the request. It uses no `Illuminate\Http` class except the HTTP client and uploaded files, no `Inertia` class, and none of `request()`, `session()`, `redirect()`, `response()`, `back()`, or `view()`.
- A response has no `success` key. The status code carries success.
- Pick the response format with `expectsJson()` or a route middleware group. Do not check request paths in the exception handling.

## Domains

- `Applications`. The `Application` model, the presets in `ApplicationType`, `EnvProfileKind`, and the actions that create an application and change its env profile.
- `Audit`. The `AuditEvent` model, the record of what users and opencloud changed. The settings page shows it as the audit log.
- `Backups`. Database dumps on request and each night, and the copy of the dashboard database. It also holds the bucket copies, the backup target, and the restore of one database from a dump.
- `Builds`. `Builder` clones the commit, writes the Dockerfile of the preset, and runs `buildctl` against BuildKit. `Git` is the only class that runs `git` against a remote.
- `CloudProviders`. The provider keys (`ProviderCredential`), the provider guides in `Providers`, their checks, and the Cloudflare API client.
- `Clusters`. The `Cluster` model and the clusters that opencloud creates with OpenTofu or connects with a kubeconfig. It also holds the opencloud parts on a cluster and the image copy to the registry of a cluster.
- `Datastores`. MySQL, Postgres, Valkey, and RustFS. It holds the provisioner registry for the standard, managed, and external sources, and the attachments. It also holds the database names and the env profiles that turn a connection into env values.
- `Deployments`. The `Deployment` model and its state machine, `RunDeployment`, the environment manifests, the merged env values, the deploy hooks, and the deployment log.
- `Environments`. The `Environment`, `EnvVar`, `Process`, and `CommandRun` models. It holds env values, processes, settings, colors, one-off commands, logs, and the live status.
- `GitHub`. The boundary to GitHub: the GitHub App, its client and installation token, and the webhook payloads `PushEvent` and `PullRequestEvent`.
- `Members`. The `User` and `Invite` models, the `Role` and `Ability` enums, and the actions for invites, roles, and the owner.
- `Previews`. The preview environments of pull requests: open, sync, and close a preview, the preview settings, and the pull request comment.
- `Restore`. The restore of a lost install on a new server from the backup bucket.
- `Routing`. Hostnames and the traffic to them. It holds the `Domain` and `DashboardDomain` models, the DNS checks, and the Cloudflare DNS records. It also holds the certificate checks, the free sslip.io domains, and the Caddy config and sync. The `Domain` model is a hostname, so the folder name is `Routing` and not `Domains/Domains`.
- `Upgrades`. The daily check for a newer opencloud release. `bin/opencloud upgrade` does the upgrade itself.

## Where each kind of class goes

| Kind | Folder | Example |
|---|---|---|
| Action | `app/Domains/<Domain>/Actions` | `StartDeployment` |
| Job | `app/Domains/<Domain>/Jobs` | `RunDeployment` |
| Model | `app/Domains/<Domain>/Models` | `Environment` |
| Enum | `app/Domains/<Domain>/Enums` | `DeploymentStatus` |
| Policy | `app/Domains/<Domain>/Policies` | `EnvironmentPolicy` |
| Exception | `app/Domains/<Domain>/Exceptions` | `RepositoryDoesNotFit` |
| Observer | `app/Domains/<Domain>/Observers` | `DeploymentObserver`, from before the rule against model events. Add no new observer. |
| Other domain class | The domain root or a topic folder | `CaddyConfig`, `Datastores/Env/LaravelProfile`, `CloudProviders/Checks/AwsCheck` |
| Controller | `app/Http/Controllers` | `ClusterController` |
| Form Request | `app/Http/Requests` | `CreateClusterRequest` |
| Middleware | `app/Http/Middleware` | `HandleInertiaRequests` |
| Console command | `app/Console/Commands` | `CopyDatabase` |
| Service provider | `app/Providers` | `AppServiceProvider` |
| Gateway or shared helper | `app/Support` | `Support/Kube/Kubectl`, `SecretMasker` |

A gateway in `app/Support` keeps its exception next to the gateway class, for example `Support/Kube/KubectlException`.

## Inside a domain

- **Actions** are the unit of change. A controller, a command, or another Action calls one. Read `.claude/rules/actions-jobs.md`.
- **Jobs** run queued work: slow work, work against an external system, and work that waits for an outside state.
- **Models** hold relations, casts, `$fillable`, `$hidden`, the factory and policy attributes, and small query helpers such as `DashboardDomain::current()`. Read `.claude/rules/database.md`.
- **Enums** cast columns. An enum method holds a rule about the value, for example `DeploymentStatus::canMoveTo()` or `Role::allows()`.
- **Policies** answer whether a user may do one thing to one record. Read `.claude/rules/authorization.md`.
- **Other classes** live at the domain root or in a topic folder. They read and compute. They do not write records.
  - A service is a stateless class with several public methods, for example a gateway to an external system (`GitHubClient`, `CloudflareApi`, `CaddySync`).
  - A manifest class builds Kubernetes objects as plain PHP arrays from models, with no side effects (`EnvironmentManifests`, `CommandManifests`, `DatastoreManifests`, `ClusterPartsManifests`).
  - A registry lists the implementations of an interface and finds one (`Provisioners`, `ClusterProviders`, `Providers`).
  - A value object carries two or more values that belong together (`SqlConnection`, `PushEvent`, `CheckResult`).
  - An interface marks a real extension point (`ClusterProvider`, `Provisioner`, `ManagedStack`, `EnvProfile`).

## The seams between domains

Opencloud has no domain events and no listeners. A domain uses another domain in four ways:

1. It reads the models and enums of the other domain. Relations and simple queries across domains are fine.
2. It calls an Action of the other domain to change its records. `CreateApplication` calls `AttachDatastore`, `AddDomain`, `AddProcess`, `SetEnvVar`, and `StartDeployment`.
3. It dispatches a Job of the other domain. `RunDeployment` dispatches `SyncCaddy`.
4. It calls a stateless class of the other domain: a service, a manifest class, or a registry. `RunDeployment` uses `Builder`, and `Backups` uses `Provisioners`.

A domain never writes a model of another domain directly. When the owning domain has no Action for the change, add one there.

## Support

- `App\Support\Kube` is the gateway to Kubernetes. `Kubectl` is the only class that runs `kubectl`. `Labels` holds the shared labels. `FakeKubectl` is its test double.
- `App\Support\Tofu` is the gateway to OpenTofu. `Runner` is the only class that runs `tofu`. `Stack` describes one run, and `TofuLog` writes the masked output.
- `SecretMasker` masks secrets in text. `TerminalText` removes terminal colors.
- Put a class in `Support` only when several domains use it as a gateway, or when it belongs to no domain. A class that one domain uses stays in that domain.

## Laravel features that opencloud avoids

- **Facades and service location in domain code.** Inject the dependency in the constructor. Do not call a facade, `app()`, or `resolve()` in `app/Domains`. `.claude/rules/actions-jobs.md` lists the class to inject for each facade.
- **`$with` on a model.** Eager load in the controller that renders the data.
- **Accessors and mutators.** Use a cast, or compute the value where you render it.
- **Model events and observers.** Do the follow-up work in the Action or Job that makes the change. Do not use `booted()` hooks, `::observe()`, `$dispatchesEvents`, or `#[ObservedBy]`.
- **`DB::table()` in `app/`.** Query through the Eloquent model. A migration is the exception, because it must not use model classes.
- **`LOWER(column)` in SQL.** The function stops the index. Store the value in one case, as `AddDomain` stores the hostname in lowercase, and compare it with `where()`.
- **Class names in morph columns.** Each model has an alias in `Relation::enforceMorphMap()`. A class name in the database breaks when the class moves.
- **Console commands by signature string in PHP.** Refer to a command by its class: `Artisan::call(CopyDatabase::class)`. A framework command too: `RestartCommand::class` for `queue:restart`.
- **Policy discovery by name.** Bind each policy with `#[UsePolicy(XPolicy::class)]` on its model. Do not use `Gate::policy()` or a `$policies` array.
- **A trait with one consumer.** Put its code into that class.
- **`env()` outside `config/`.** Read each setting with `config()`. The web container runs `php artisan config:cache` at each start, and the tests change settings with `config([...])`.

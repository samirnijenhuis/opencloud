# Code review standards

These rules come from review corrections. Treat each rule in `.claude/rules/` as a review comment that you must prevent.

A change that a user starts has a standard chain. It has a Form Request, a policy, an Action, and an audit event. Slow or external work goes in a Job. A test asserts the changed rows or the queued job.

## Simplicity

- Do not add a class, a trait, a scope, or middleware where one inline expression works. One class with a parameter is better than several near copies. A trait with one consumer goes into that class.
- A value object carries at least two values that belong together, as `SqlConnection` and `CheckResult` do. A private method with one line and one caller goes inline.
- Use a transaction only for an invariant across several writes, and name the invariant. Do not wrap one write in `DB::transaction()`. Do not add `lockForUpdate()` or a cache lock for a race that the code does not have.
- Retry only work that is safe to run again against an external system, for example `SyncCaddy` and `CheckDomain`.
- Give each mechanism a reason that fits in one sentence. Reviewers reject defensive code without a reason.
- The small forms of defensive code count too. Examples are `try` and `catch`, `rescue()`, a `match` default arm, and `?->` on a value that cannot be null. A fallback for a state that the domain rules out also counts. Validate and catch only at real boundaries. If you add a guard, name the real failure that it prevents in the commit message. If you cannot name it, delete the guard.
- Fix a cause once, where it starts: the entry point, the class that owns the concept, or the manifest class. Do not add a check at each call site.
- Never ship dead code.

## Framework features to use

Before you write a mechanism, look for it in this order: this codebase, Laravel, and the installed packages. The packages are in `composer.json` and `package.json`. When you find the mechanism, use it. Read the docs or the source in `vendor/` before you call an API that you did not see before. Never invent a method or an option.

- Helpers: `once()`, `collect()` pipelines, `Str::`, `data_get()`, and `$model->is()`.
- Eloquent: `updateOrCreate()`, `upsert()`, `isDirty()`, `wasChanged()`, `withCount()`, and `->each()` on the query.
- Requests: `$request->enum()`, `$request->user()`, `Rule::enum()`, `Rule::in()`, and route `throttle` middleware.
- Queues: `WithoutOverlapping`, `ShouldBeUniqueUntilProcessing`, `$this->release()`, `retryUntil()`, and `->afterCommit()`.
- Tests: `Http::fake()`, `Process::fake()`, `Queue::fake()`, and `assertInertia()`.

## Opencloud pieces that already exist

Use these pieces before you write a new one.

- **Kubernetes.** `Kubectl::for($cluster)` with `apply()`, `get()`, `delete()`, `exec()`, `logs()`, and `rolloutStatus()`. `Labels` gives the shared labels. Build each manifest as a PHP array in the manifest class of its domain: `EnvironmentManifests`, `CommandManifests`, `DatastoreManifests`, or `ClusterPartsManifests`.
- **OpenTofu.** `Runner::apply()` and `Runner::destroy()` with a `Stack`. `TofuLog` writes the masked output to `tofu_log`.
- **Caddy.** `CaddyConfig::forCluster()` builds the config, and the `SyncCaddy` job sends it. Do not post to the Caddy admin API yourself.
- **Git and GitHub.** `Git::head()`, `Git::defaultBranch()`, and `Git::checkout()`. `GitHubClient` calls the GitHub API with the installation token.
- **Datastores.** `Provisioners::for($kind, $source)` and `Provisioners::of($datastore)` give the provisioner. `AttachmentEnv::write()` writes the env values of one attachment. `DatabaseNames` holds the name rules.
- **Env values.** `EnvironmentVariables::runtime()` and `build()` merge the values of an environment.
- **Logs and secrets.** `DeploymentLog::for($deployment)->write()`, `SecretMasker::forEnvironment()`, and `TerminalText::plain()`.
- **Audit.** `AuditEvent::record()`.
- **Domains and DNS.** `DefaultDomain::add()`, `DnsResolver::ipv4()`, and `CloudflareDns`.
- **Validation.** `Process::rules()`, `ExternalConnectionForm::rules()`, `StoreApplicationRequest::HOSTNAME`, and `DomainRequest::normalizeHostname()`.
- **Live state.** `LiveStatus::environment()` and `LiveStatus::cluster()`.
- **Tests.** `FakeKubectl`, `FakeProcesses`, `FakeTofu`, `BuildsEnvironments::makeEnvironment()`, and `WithGitHubApp::fakeGitHub()`.

## Scope

- Change only what the task needs. Each changed line serves the goal of the task.
- Do not refactor, rename, reformat, or improve other code in the same change. When you see a side problem, note it and fix it in its own change. Unrelated edits hide risk, and the reviewer must read the change twice.
- Never remove a feature, a test, or a behavior that the task does not name.
- Build only what the task asks for. Add no extra UI, props, config, commands, or compatibility layers. Put an idea in the PR under "Proposals, not built".
- Do not change these files unless the task needs it:
  - `composer.json`, `composer.lock`, `package.json`, and `package-lock.json`.
  - `.env.example`, `phpunit.xml`, `bootstrap/app.php`, and `bootstrap/providers.php`.
  - `install.sh`, `bin/opencloud`, and `platform/*.yaml`.
- Put a new package in its own change.
- Never apply again a change that the user reverted in the working tree.
- When the code and `docs/architecture.md` disagree, fix one of them in the same commit.

## Replacing code

- Before you replace a mechanism, list what it does today. Include its stored settings, the pages that show it, its jobs, its audit events, and its Kubernetes objects. Keep each item, or name its removal in the PR.
- When the replacement is complete, delete the old route, controller, Action, and props in the same change. Search for each use, also in the TSX `route()` calls and the shell scripts.
- Only one dashboard runs at a time, so old dashboard code gets no compatibility path. Queued jobs are the exception. A job keeps its class name and constructor while a queued copy can exist. `ProvisionManagedDatabase` and `TearDownManagedDatabase` kept their names when they started to serve each managed kind.

## Idempotency

- Commands, queued jobs, and migrations that move data must reach the correct state, however often they run and wherever they start. Crashes, restarts, and retries are normal. If a half-done run changes the next result, each restart becomes a debugging session.
- Ask two questions for each operation that changes state. What happens when it runs twice? What happens when the last run crashed halfway?
- Converge on a desired state instead of a list of steps. `Kubectl::apply()` sends the full state, `tofu apply` changes only what differs, and `RestoreInstall` skips the steps that `restore.json` marks as done.
- Clean up by content, not by age or order. `RunDeployment` deletes the process Deployments that are not in the process list, selected by label.

## Performance and external calls

- Read Kubernetes state with one `kubectl` call for each resource type and a label selector. Never make one call for each pod, as `LiveStatus` shows.
- Cache a value that the UI polls for a few seconds. `LiveStatus` keeps each answer for 10 seconds.
- Eager load the relations that a page renders, in its controller.
- Query through Eloquent relations and scopes (`whereHas()`, `withCount()`), not `whereRaw()`, unless Eloquent cannot express the query.
- Append to a long text column in SQL, as `DeploymentLog::write()` does. An update of the whole column for each new line is slow for a long log.
- Before an external API call in a loop, read the rate limit of the provider. Handle the retries and the errors. Put the numbers in the PR.

## Meta rules

1. **Search before you write.** Most rejected code copied something that existed. Find the closest existing implementation and copy its shape. When the task names a reference, follow it exactly. Keep each string key or format that more than one place uses in one place.
2. **Justify each layer** in one sentence, or do not add it.
3. **Keep to the scope.** Do not touch, remove, or improve anything outside the task.
4. **The commit carries the reason.** Each guard, fallback, or compatibility branch that is not obvious gets one sentence in the commit message or the PR. It names the failure that it prevents. When you add something next to a similar existing thing, say why the existing thing does not fit.
5. **Apply a review comment only where it is.** If it seems to apply elsewhere, ask. Reply in the thread with the change and the commit.

## Working agreements

- Never block on the human. Make a reasonable decision, continue, and let the human correct it later. Reversible work goes on without a question. An action that you cannot undo needs a confirmation, and so does an action that leaves this machine. Examples are a force push, a deploy, deleted data, and a message to someone.
- When the prompt asks a question or asks for advice, answer it. Do not edit files.
- For a new feature, a change across domains, or a data migration, post a short plan, then continue without waiting. Name the existing code that you copy, the files, your decisions on open questions, and what is out of scope.
- When you offer options, show the difference on one real example from this repository. Then recommend one.
- Debug from evidence. Trace the real path before you edit: the route, the Form Request, the Action, the Job, and the `kubectl` calls. Treat facts that the user gives as true. After two failed fixes, stop and list what you know.
- Do not write "done", "fixed", or "tests pass" until you ran the check and can quote its result.
- Call your own additions proposals until someone confirms them, also in PR descriptions.

# AGENTS.md

This file tells a coding agent how to work in the opencloud repository. The Laravel app is in `dashboard/`. It is the dashboard, the API, and the queue worker.

`docs/architecture.md` is the contract for the code. It gives the system design, the names, the action table, the queues, and the roles. Read it before you change the dashboard, and do not copy it into a rule file. When the code and that document disagree, fix one of them in the same commit. `docs/superpowers/specs/2026-10-22-opencloud-design.md` says what opencloud does and why.

The rules for the dashboard code live in `.claude/rules/`. An unscoped rule loads in every session. A path-scoped rule loads when you work on a file that matches its `paths`. Before you write a file of a kind that you did not read this session, read the matching rule. An action, a job, a policy, a migration, and a test are examples of a kind.

## Core principles

- **SOLID.** Give each class one reason to change. Depend on an interface only at a real boundary. `ClusterProvider` has one class for each cloud, and `Provisioner` has one class for each datastore kind and source.
- **Build with confidence.** Write the code that the task needs, not armor against failures that cannot happen. Do not add feature flags, kill switches, checkpoint tables, change-detection hashes, or interfaces with one implementation by reflex. Trust the framework and the internal callers. Validate and catch only at a real boundary: user input, a binary such as `kubectl`, or an external API. If you add a guard, name the real failure that it prevents. If you cannot name the failure, delete the guard.
- **Converge at the boundaries.** A job that calls `kubectl`, OpenTofu, or a cloud API can stop halfway and run again. Make it reach the same end state when it runs twice or after a crash. `Kubectl::apply()` sends the full desired state each time for this reason.
- **Study before you write.** Find an existing feature of the same kind and copy its shape. Do not add a second way to do a solved thing.
- **Think about the deployed apps.** An env name, a Kubernetes label, or a Caddy route reaches every app that opencloud runs. `EnvPinTest` compares the Laravel env of each attachment shape with `tests/Fixtures/env/laravel.json`. A change to that file changes the env of every deployed Laravel app.
- **Test what you change.** Cover each change with a test that runs the real behavior and fails when you revert the change. Read `.claude/rules/testing.md`.

## Quick facts

- Run the PHP commands in `dashboard/`.
- Run the tests with `XDEBUG_MODE=off php artisan test`. Add `--filter=<test name>` to run one test.
- Run the Architecture suite with `vendor/bin/phpunit --testsuite=Architecture`. Each failure message names the rule file that holds the fix.
- Format the PHP files that you changed with `vendor/bin/pint --dirty`. CI runs `vendor/bin/pint --test` and fails on a file that Pint would change.
- CI also runs `npm run format:check`, `npx eslint .`, and `npx tsc --noEmit` for the frontend.
- Opencloud has no PHPStan, no Rector, and no coverage gate. Pint and the test suites are the gates.
- After a change to deploys, builds, datastores, env values, Caddy, or the dashboard forms, run the `verify-opencloud` skill.
- The dashboard database is SQLite in production and in the tests. Read `.claude/rules/database.md`.

## Core standards

- **Comments.** Do not write a comment that restates the code. Keep a short comment only for a reason that the code cannot show. An example is the comment in `SetEnvVar` that says why a kept secret cannot become a plain value. A docblock can also carry types that PHP cannot express (`@param array{...}`, `@return list<string>`) and `@throws`. Do not remove the existing comments.
- **Names.** Use the names in the Names table of `docs/architecture.md`. Read `.claude/rules/naming.md`.
- **Messages.** Write each message that a user sees as short, plain sentences that say what to do. An example is "The environment has no live deployment. Deploy it first." Opencloud has no translation files, so write the English text in the code.
- **Secrets.** Send each text from `kubectl`, `tofu`, `git`, or a database server through `SecretMasker`. Do this before the text goes into the database, a log, an audit event, or a response.
- **Routes.** Refer to a route by its name: `route('environments.show', $environment)` or `to_route('environments.show', $environment)`.
- **Directories.** Name a directory in StudlyCase, for example `app/Domains/CloudProviders`.

## Rule files

These rules load in every session:

- `.claude/rules/architecture.md` gives the app tree, the domains, the place of each kind of class, the seams between domains, and the Laravel features that opencloud avoids.
- `.claude/rules/naming.md` says how to name new code and which opencloud names are easy to mix up.
- `.claude/rules/code-review-standards.md` holds the review rules: simplicity, the existing pieces to reuse, scope, idempotency, performance, and the working agreements.
- `.claude/rules/registration-checklists.md` lists the changes that need a second edit in another file.
- `.claude/rules/hidden-mechanisms.md` lists the behavior that you cannot see at the call site.

These rules load for matching paths:

- `.claude/rules/actions-jobs.md` covers actions, jobs, queues, audit events, dependencies, and the scheduler.
- `.claude/rules/authorization.md` covers Form Requests, policies, roles and abilities, and client identifiers.
- `.claude/rules/database.md` covers the SQLite database, models, columns, and migrations.
- `.claude/rules/testing.md` covers what a test proves, where it goes, the fakes, the factories, and the Architecture suite.

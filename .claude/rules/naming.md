# Naming

## Use the names of the domain

- The Names table in `docs/architecture.md` gives the name of each concept and the names to avoid. Use those names in PHP, in the UI, in Kubernetes labels, and in docs. Read the table before you name a new concept.
- Do not invent a word for a concept that has a name. Use one term in the database, the code, the UI, and the docs.
- A renamed enum case keeps its value. The database rows and the pages read the value, not the case.

These opencloud names are easy to mix up:

- A `Domain` model is a hostname of an environment, and the `Routing` domain owns it. A domain in `App\Domains` is an area of the code.
- `web` is a process of each environment, but it is not a `Process` row. The environment holds its settings, and `Process::WEB` names it.
- The environment kinds are `permanent`, `preview`, and `preview_template`. The preview template holds the settings of future previews and never deploys.
- A datastore kind (`mysql`, `postgres`, `valkey`, `rustfs`) is not its source (`standard`, `managed`, `external`). Neither is its resource family (`database`, `key_value`, `bucket`).
- The cluster kinds are `local`, `connected`, and `created`. Opencloud creates a `created` cluster with OpenTofu.
- An env value is an `EnvVar` row. Its source is `user` or `datastore`.

## Naming new code

- Name a class by its role. Do not put its folder or namespace in the name.
- Name an Action, a Job, and a console command by a verb phrase, without a suffix: `StartDeployment`, `ProvisionCluster`, `CopyDatabase`.
- Name middleware by what it does, without a suffix: `HandleInertiaRequests`.
- Name an enum by the value that it holds, without an `Enum` suffix: `DeploymentStatus`.
- End the name of a controller with `Controller` and the name of a Form Request with `Request`. End a policy with `Policy`, a service provider with `ServiceProvider`, and a test with `Test`.
- Name a Form Request after the Action that it feeds: `AttachDatastoreRequest` feeds `AttachDatastore`.
- Write enum cases in `UPPER_SNAKE_CASE`: `MANAGE_CREDENTIALS`, `PREVIEW_TEMPLATE`. Write the backing value in lowercase snake_case: `'preview_template'`.
- Use the model name in snake_case as its morph alias: `datastore_attachment`.
- Name an audit action `<subject>.<what happened>` in snake_case: `deployment.started`, `datastore.default_changed`.
- Take each Kubernetes label from a `Labels` constant, for example `Labels::PROCESS` for `opencloud.dev/process`. Do not write a label by hand.
- Name a method after what it returns: `CaddyConfig::forCluster()`, `EnvironmentVariables::runtime()`. Only an Action has `execute()`.
- Name a test method `test_` and a sentence in snake_case that states the behavior: `test_a_developer_sees_the_domains_but_cannot_change_them`.

## Names that match reality

- A name must fit every use of the thing. A variable that holds the result of `->update()` is a bool. A class that only dispatches a job does not carry the name of the work that the job does.
- A state says what happened: `superseded`, `timed_out`. A flag says its effect: `is_protected`, `auto_deploy`, `exclusive`. A field says which record or relation it reads: `triggered_by`, `datastore_attachment_id`.
- Return the end result, not parts that each caller must combine. `Provisioners::notOffered()` returns the message that the menu shows, or null. `DefaultDomain::hostname()` returns the full hostname.

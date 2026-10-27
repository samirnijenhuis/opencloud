---
paths:
  - "dashboard/app/**/*.php"
  - "dashboard/routes/**"
---

# Validation and authorization

Each request that changes something follows one chain: Form Request, policy, Action. The Form Request validates the input and asks the policy. The controller calls the Action and returns a response.

## Form Requests

- Put the validation and the authorization of an endpoint in a Form Request in `app/Http/Requests`. The rules go in `rules()`, and the check goes in `authorize()`.
- A controller method that calls an Action takes a Form Request.
- Authorize in a Form Request also for a page that only reads. Do not call `Gate::authorize()`, `abort()`, `abort_if()`, `abort_unless()`, or `$request->validate()` in a controller.
- Let route model binding answer 404 for a missing record. Do not look the record up again in the controller.
- Read the user with `$request->user()` in a controller and `$this->user()` in a Form Request. Never use `Auth::` or `auth()`.
- Give each behavior its own route, Form Request, and controller method. Do not branch on `$request->isMethod()` or on a mode parameter.
- Clean the input in `prepareForValidation()`, before the rules run. `DomainRequest::normalizeHostname()` turns `https://Shop.Example.com/` into `shop.example.com`.
- Parse typed input in the Form Request. Add a public method that returns the enum or the value object. Call it from the controller, as `AttachDatastoreRequest::source()` and `connection()` show.
- Keep a rule set that several requests share in one static method: `Process::rules()` and `Process::messages()`, `ExternalConnectionForm::rules()`, `StoreApplicationRequest::HOSTNAME`.
- Give each rule that a user can break a message in `messages()`: "This domain is already in use."
- Name a secret field `password` when you can. Laravel does not trim or flash a `password` field. For another secret key, read `.claude/rules/registration-checklists.md`.
- Limit a public endpoint with route `throttle` middleware, as `routes/webhooks.php` does for the deploy hook.
- Prove a webhook with its signature before you read the payload. `GitHubWebhookController` checks `X-Hub-Signature-256` with `hash_equals()`, and `DeployHook::find()` compares tokens with `hash_equals()`.

## Identifiers from the client

- Check each identifier that the client sends. A query parameter or a body field never grants access.
- A route model gets the policy check in `authorize()`, on the record itself or on the record that owns it.
- Give an id in the body a rule that proves the record exists: `'cluster_id' => ['required', 'integer', 'exists:clusters,id']`.
- Give the name of a child record a rule against the route model: `Rule::in($environment->processNames())`.
- A route binds a child record by its own id: `env-vars/{envVar}`, `processes/{process}`, `datastore-attachments/{attachment}`. Authorize it against its owner: `$envVar->environment`, `$domain->environment->application`.
- Do not repeat a check that the middleware or the rules already make. The `auth` middleware already refuses a guest, and an `exists` rule already refuses an unknown id.

## Roles and abilities

- `App\Domains\Members\Enums\Role` has three cases: owner, admin, and developer. `Role::allows(Ability)` is the one map from roles to abilities. The `Ability` enum lists the rights.
- Check an ability, never a role. Write `$user->role->allows(Ability::MANAGE_CREDENTIALS)`. Do not compare a `Role` case, call `in_array()` on roles, or `match` on a role.
- Grant a new ability to the smallest set of roles that needs it. Keep a right that touches the cloud account, a credential, or the members on the owner.
- A developer has no write right on a protected environment. A change to a protected environment, and a deploy of it, needs `CHANGE_PROTECTED_ENVIRONMENT`.
- When you add an ability, add a row for each role to `RoleTest::rights()`. `test_the_rights_table_covers_every_role_and_ability` fails until every pair has a row.

## Policies

- The policies are `ApplicationPolicy`, `EnvironmentPolicy`, `ClusterPolicy`, `ProviderCredentialPolicy`, and `UserPolicy`.
- A child record uses the policy of its owner. `EnvVar`, `Process`, and `DatastoreAttachment` use `EnvironmentPolicy`. `Domain` uses `ApplicationPolicy`.
- Give a new model that the UI changes, and that has no owner, its own policy in `app/Domains/<Domain>/Policies`.
- Bind each policy on its model with `#[UsePolicy(XPolicy::class)]`.
- A policy method takes the user first and returns `bool`. It asks `$user->role->allows(...)` and the state of the record.
- Write a policy method as early returns, with one check for each `if`. Do not chain checks with `&&`, and do not pick the ability with a ternary.

```php
public function update(User $user, User $member): bool
{
    if ($user->is($member)) {
        return false;
    }

    return $user->role->allows(Ability::MANAGE_MEMBERS);
}
```

- In `authorize()`, ask the policy: `return $this->user()->can('update', $this->route('environment'));`. Call `role->allows()` outside a policy only for an ability that has no record.

## Flags for the UI

- Compute each `can` flag of a page with the policy method that guards the endpoint: `'update' => $user->can('update', $environment)`, as `PresentsEnvironment` does.
- The page hides a control that the user cannot use. The Form Request still refuses the request.

## Code that does not follow these rules yet

- Many controllers still call `Gate::authorize()`, `abort_unless()`, or `$request->validate()`. Some Form Requests and controllers call `role->allows()` where a policy method fits.
- `EnvironmentPolicy` picks the ability with a ternary, and `UserPolicy` chains its checks with `&&`.
- `tests/Architecture/baseline.json` lists the `abort` calls, the inline validation, and the controller methods without a Form Request. No check covers `Gate::authorize()` or the policy style yet.
- New code follows the rules above. When you change one of these methods, bring it to the rules and lower the baseline.

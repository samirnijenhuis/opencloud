# Registration checklists

Each change below needs a second edit in another file. Most of them fail without an error when you forget that edit. The Architecture suite checks the items that say so.

- **New model.** Add its alias to `Relation::enforceMorphMap([...])` in `app/Providers/AppServiceProvider.php`. Use the model name in snake_case, for example `datastore_attachment`. An audit event can point at any model, so each model needs an alias. Bind its factory with `#[UseFactory]`. The suite checks the alias.
- **New policy.** Bind it on its model with `#[UsePolicy(XPolicy::class)]`. The suite checks the binding.
- **New queue name.** Add a worker for it. Add a `queue:work --queue=<name>` case to `dashboard/docker/entrypoint.sh` and a container with that argument to `platform/dashboard.yaml`. A job on a queue without a worker never runs. The suite checks it.
- **New service provider.** List it in `dashboard/bootstrap/providers.php`. An unlisted provider never boots. The suite checks it.
- **New middleware.** Register it in `dashboard/bootstrap/app.php` or in a routes file. Middleware that nothing registers never runs. The suite checks it.
- **New recurring work.** Schedule it in `dashboard/routes/console.php`. The `scheduler` container runs `schedule:work`. A job that nothing schedules or dispatches never runs.
- **New console command.** Put it in `app/Console/Commands`. Laravel finds the commands in that folder. A command class in another folder does not exist for Artisan. The suite checks it.
- **New or renamed command signature.** Update each shell caller: `dashboard/docker/entrypoint.sh`, `bin/opencloud`, `install.sh`, `platform/restore.yaml`, `.github/workflows/provider-links.yml`, and `tests/e2e/`. The entrypoint runs `opencloud:bootstrap` only when that command exists, so a rename skips the bootstrap without an error.
- **New setting from the environment.** Read it with `env()` in `config/opencloud.php`, and read it with `config()` everywhere else. Do these steps too:
  1. Set the production value in the `env` list of the `web` container in `platform/dashboard.yaml`. The other three containers share that list through `*env`.
  2. When the installer picks the value, add a `__NAME__` placeholder to the manifest and a `sed` line to `install.sh`.
  3. Pass an installer setting through the `upgrade` command in `bin/opencloud`, or the next upgrade loses it.
  4. Describe the setting in `docs/architecture.md`.
  5. Give the tests a value in `phpunit.xml`, or set it with `config([...])` in the test.
- **New secret column.** Give it the `encrypted` cast, and list it in `$hidden`. Add a row to `DataModelTest::encryptedFields()`. Without `$hidden`, `toJson()` and the Inertia props send the decrypted value.
- **New secret form field.** Name it `password` when you can. Otherwise add its key to `dontFlash([...])` in `dashboard/bootstrap/app.php`. Without that, a failed validation writes the value into the `sessions` table.
- **New kind of secret in output.** `SecretMasker::forEnvironment()` masks the secret env values and the attachment passwords of one environment. A secret that lives elsewhere, for example a root password or a token, needs its own `SecretMasker` where the code writes the text.
- **New Ability.** Add the case to the `match` in `Role::allows()`. Add a row for each role to `RoleTest::rights()`.
- **New datastore kind or source.** Add the row to `Provisioners::table()`. For a managed kind, also add the row to `ManagedResources::find()`. The add menu of the environment page shows only the rows of `Provisioners::table()`.
- **New cluster provider.** Write one class that implements `ClusterProvider`, and add one OpenTofu module under `tofu/`. Add the class to the list in `ClusterProviders`.
- **New preset.** Add the case to `ApplicationType` with its defaults. When the preset writes its own Dockerfile, add a class in `app/Domains/Builds` and a template in `dashboard/resources/docker/`. `NodeDockerfile` and `node.Dockerfile` are an example. Add a fixture to `.claude/skills/verify-opencloud/fixtures/`.
- **New top-level test folder.** Add it to a `<testsuite>` in `dashboard/phpunit.xml`. PHPUnit runs only the listed folders, and CI runs `./vendor/bin/phpunit`.

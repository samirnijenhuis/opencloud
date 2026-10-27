# Environment values

A user sets env values for an environment, marks some as secret, and picks when each one is available: at runtime, at build time, or both. A secret is write-only in the dashboard.

## Sub-features

- `env-runtime` gives the running app a value, for example `VERIFY_MARKER`.
- `env-build` gives the image build a value as a BuildKit secret, for example `VERIFY_BUILD`.
- `env-secret` gives the app a secret that the dashboard never shows again.
- `env-change` changes a value, which takes effect on the next deploy.

## How to get to it (user POV)

- Add values in the `Environment values` part of the create form.
- Choose `Settings`, then `Environment values`, and add or replace a value. Then choose `Deploy`.
- The settings form posts to `POST /environments/<id>/env` with `key`, `value`, `is_secret`, and `available_at`.

## Driving it with matrix.py

Preconditions:

- `matrix.py doctor` reports `OK` on every line.

- **Runtime and build values.** Create apps with both kinds. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'static-none-none' --only 'php-none-none'`. The output shows `PASS step 1: the runtime env value (<cell>-v1)` and `PASS step 1: the build saw its env value or command (<cell>)`.
- **Secret.** Read the secret in the app, not in the dashboard. In the PHP, Laravel, Node.js, Python, and Ruby cells, the output shows `PASS step 1: the app reads the secret` and `PASS the settings page never shows the secret value`. The `static` and `site` cells show `SKIP step 1: the app reads the secret`.
- **A static site.** A static site gets only build values. Run `.claude/skills/verify-opencloud/scripts/matrix.py run --set full --only 'site-none-none'`. The output shows `PASS step 1: the build saw its env value or command (site-none-none)` and `SKIP step 1: the runtime env value (the site fixture does not report marker)`.
- **Change.** Change `VERIFY_MARKER` and deploy. The output shows `PASS the env value VERIFY_MARKER changes (302)` and `PASS step 2: the runtime env value (<cell>-v2)`.
- **Proof.** Open `step-1-verify.json` and `step-2-verify.json` of a cell. `marker` changes from `-v1` to `-v2`, and `secret_sha` is the SHA-1 of the secret.

## Gotchas

- A changed value does nothing until the next deploy. Do not check the site before the deployment is live.
- A Laravel app caches its config when it starts. Read env values in a config file, never with `env()` in a controller.
- The Laravel template passes build values only to the Composer and npm steps, not to the build command. A Dockerfile app reads them with `RUN --mount=type=secret,id=<KEY>`. The Node.js template puts them in the env of the install, the build script, and the build command. The static template puts them in the env of the install and the build script.
- The `python` and `ruby` fixtures write `$VERIFY_BUILD` in their build command. The check fails when the Python or Ruby template does not give the build command the build values.
- `config.json` in the evidence hides the secret. Never print `.verify/state/owner.json` or a secret value.

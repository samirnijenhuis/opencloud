# Opencloud design

Date: 2026-10-22. Status: approved on 2026-10-23.

Opencloud is an open-source platform that you install on your own VPS. It gives you a dashboard like Laravel Cloud. You connect a GitHub repository, and opencloud builds and deploys the app on Kubernetes that you control. You use your own servers, your own cloud accounts, and your own API keys.

## How to read the labels

Each decision in this document has a label.

- **[S]** Samir decided it.
- **[C]** Claude proposed it in the design conversation, and Samir did not object.
- **[C-new]** Claude proposed it while writing this document. Samir has not reviewed it yet.
- **[B]** The builder decides it. The document gives a lean where one exists.

## Why opencloud exists

Laravel Cloud has a good foundation, but three problems make it unusable for Samir [S].

- The price is too high.
- Laravel Cloud decides which services sit in front of your sites, and you cannot choose another setup. Samir likes Cloudflare. The problem is the lock-in, not Cloudflare.
- You cannot change its parts.

Coolify is self-hosted, but it has its own problems [S].

- It is slow and contains more than the job needs.
- Its domain routing works until it breaks, and then the user fights with it.
- Preview environments get real API keys, for example an OpenAI key. You cannot give a person access to Coolify and also keep those keys from that person.

Opencloud keeps the Laravel Cloud workflow and removes these problems. Laravel Cloud is hard to copy because it offers sharding and multi-tenancy. Opencloud does not need either [S].

## The main goal is "it just works"

Samir wants opencloud to be known for one property. It just works [S]. Each other decision in this document must serve that goal. When a feature conflicts with it, the feature waits.

In practice, "it just works" means these things [C].

- The install either completes or tells you exactly what to fix.
- Each field in the "New app" form has a default that works. A repository and a domain are enough to deploy.
- A domain with HTTPS works the first time and keeps on working.
- A failed deploy never stops the running version.
- Each error message tells you the next step in plain words.
- An API key from a provider takes one link or one command to create, and opencloud checks it as soon as you paste it [S], [C-new].
- An opencloud upgrade is one command or one click, and it is safe.

An automatic release test proves these points on each release. The section "Prove it works with the release test" describes it.

## Goals

- A person buys one VPS, runs one install command, and opens a dashboard in a browser [S].
- The person connects a GitHub repository and deploys a Laravel app with no config [S], [C].
- The person adds a domain, and the site is live on HTTPS. Opencloud issues the certificates [S].
- Apps run on Kubernetes and scale automatically [S].
- The person can run apps on other clouds, including managed Kubernetes [S].
- The person can attach a database, Valkey, and S3-compatible storage to an app [S].
- Each pull request can get a preview environment [S].
- A team can use the dashboard with roles. Preview environments never get production secrets [S], [C].

## Non-goals

- Multi-tenancy. One install serves one organization [S].
- Sharding [S].
- A hosted service. Opencloud is software that people run themselves.
- Zero-config support for frameworks other than Laravel. Other apps work if the repository has a Dockerfile [C].
- An automatic move of an app and its data from one cluster to another. A user can create the environment on the new cluster and restore a database backup there. The documentation describes the steps [C-new].
- Automatic major-version upgrades of MySQL or Postgres [C-new].

## Anti-goals

These results make opencloud a failure, even if all features work.

- A domain or a certificate needs a fight [C].
- The install pulls in a large stack of services that the user did not ask for [C].
- Opencloud forces a CDN, a proxy service, or a DNS provider on the user [S].
- A developer can read a production secret in the dashboard, or a production secret goes into a preview [C].
- A database loses data because no backup existed [C].

## Constraints

- The dashboard is a Laravel app [S].
- The dashboard UI uses Inertia with React [C].
- The dashboard uses SQLite as its database and the Laravel database queue for its jobs [S].
- The dashboard does not use Reverb. Live views poll the server every few seconds [S], [C].
- Caddy is the proxy on every cluster, and Caddy issues the certificates [S].
- Opencloud uses OpenTofu, not Terraform [C]. Terraform uses the Business Source License, which forbids embedding it in a product that competes with HashiCorp. OpenTofu uses MPL 2.0 and can encrypt its state on the client.
- The S3-compatible storage is RustFS [S]. Opencloud pins one RustFS version [C].
- The project is open source. Anyone can clone the repository and run their own copy [S].

## How the parts fit

### The dashboard VPS is also the first cluster

The installer puts k3s, a one-node Kubernetes, on the dashboard VPS [S]. Opencloud runs inside k3s as normal pods [C]. Opencloud calls this cluster `local`. The first apps run on `local`, with no cloud keys [S].

The dashboard runs as a Laravel app on `local`, with the same runtime manifests that user apps get [C]. CI builds the opencloud image and publishes it to a public registry, so the VPS does not build opencloud itself [C-new]. An opencloud upgrade is a normal deploy of a newer image. Each install thus tests the main deploy path.

The web process and the queue worker of the dashboard share one SQLite file on a volume on the VPS disk [C-new]. The `local` cluster has one node, so both processes always run on the same disk.

### Install the platform with one command

The install is one shell command, for example `curl -fsSL <url>/install.sh | bash` [S].

1. The installer checks the server before it changes anything. It checks the OS, the RAM, root access, and the ports [C]. If a check fails, the installer stops and prints what to fix.
2. The installer installs k3s without its bundled Traefik, because Caddy is the proxy [C-new].
3. The installer deploys opencloud and Caddy on `local`.
4. The installer prints a setup URL that contains a one-time setup token [C-new].

The setup token closes a security hole. Without it, the first person who opens the IP address of a new VPS becomes the owner. Only a person with the setup URL can create the owner account [C-new].

The installer asks for no API keys, because `local` runs without them. All keys go in the dashboard [S].

The dashboard is at `http://<vps-ip>:<port>` at first [S]. Later the owner can give the dashboard a domain with HTTPS [C].

The minimum server size is 2 vCPU and 4 GB RAM [C-new]. Builds, k3s, the dashboard, and the first app share this server. The first supported OS is Ubuntu 24.04 LTS [C-new].

### Create the owner on the first visit

The person with the setup URL registers. That account gets the `owner` role [S]. After that, open registration closes. Other people join by an invite link that a member copies from the dashboard. Invites need no mail server [C-new].

### Get each API key with one link or one command

Many people hate the key setup of cloud providers, for example the IAM system of AWS [S]. Opencloud makes this step short [S].

- For each provider, the dashboard shows one of two things. It shows a direct link to the page of the provider where you create the key. Or it shows one CLI command that creates a key with exactly the rights that opencloud needs [S].
- The dashboard lists the exact rights to select on that page [C-new].
- When you paste a key, opencloud checks it at once with a real API call. If a right is missing, the dashboard names the missing right [C-new].
- Opencloud asks for the smallest set of rights that its features need [C-new].
- For AWS, the command creates the policy, the user, and the access key, and it prints the two values to paste [C-new].
- The provider guides live in one table in the code. CI checks that each link still opens [C-new].

This applies to the GitHub App, to cloud providers, and to Cloudflare.

### Add clusters in two ways

A cluster is a place where apps run. Opencloud supports three kinds of cluster.

| Kind | How it starts | When |
|---|---|---|
| `local` | The installer creates it on the dashboard VPS. | Day one [S] |
| `connected` | The owner pastes a kubeconfig of an existing cluster. | Before `created` [C] |
| `created` | OpenTofu creates the cluster with the cloud keys of the owner. Then opencloud connects it like a `connected` cluster. | Later [C] |

A `connected` cluster can be any Kubernetes, managed or not [S]. Examples are DigitalOcean, GKE, EKS, AKS, Linode, and Scaleway. This path supports all providers with no provider-specific code [C].

When opencloud connects a cluster, it installs Caddy and its other parts in namespaces of its own. It does not change other software on the cluster. So a cluster that already runs other apps also works [C-new]. Caddy needs no cluster-wide resources such as CRDs, which makes this possible.

Each `created` provider needs one OpenTofu module. The modules live in the repository, so a user can read and change them [C-new]. OpenTofu state encryption is on [C].

Two costs come with managed Kubernetes [C].

- A public cluster usually needs a cloud load balancer, and the provider charges for it.
- Some providers charge a fee for the cluster control plane.

### Apps have environments, and environments have deployments

An app is one GitHub repository. Each app runs on one cluster [C]. An app has environments. Production is one environment. A preview is an environment that a pull request creates [C]. A fixed staging environment uses the same model at no extra cost.

An environment can be protected. Production is protected by default [C-new]. The section "Teams, roles, and secrets" explains what protection does.

The section "The core data shape" lists the fields.

### Build images on the dashboard VPS

Opencloud builds a container image for each deployment on the `local` cluster [C].

- For a Laravel app, opencloud generates the Dockerfile from one template that opencloud owns and tests [C-new]. It finds Laravel from `laravel/framework` in `composer.json`. It takes the PHP version and the PHP extensions from `composer.json`. If the repository has a `package.json`, the build also runs the front-end build [C].
- For any other app, opencloud builds the `Dockerfile` at the root of the repository. The user sets the port [C].

Runtime secrets are never part of the build or the image [C]. Some apps need a credential to install packages, for example `COMPOSER_AUTH` for Laravel Nova or Flux Pro, or an npm token. For that case, an env value can be available at build time. The build gets it as a BuildKit secret mount, so the value never goes into the image. A preview build gets the build values of the preview template, never those of production [C-new].

An app on a cluster other than `local` must start new pods while the dashboard VPS is down. So that cluster must not pull images from the dashboard VPS [C]. The builder decides how. Lean: push each image to a registry near the cluster, for example the registry of the cloud provider.

### Run a Laravel app as a list of processes

Each environment runs web and a list of other processes [C-new]. Web serves HTTP and autoscales between the minimum and the maximum instance count. Each other process has a name, a command, an instance count, and an exclusive flag. An exclusive process runs exactly one instance, and its old pod stops before the new pod starts.

A new Laravel app gets two processes by default. The user can change them, remove them, and add more.

| Process | Command | Scaling |
|---|---|---|
| web | the HTTP server | autoscales between the minimum and the maximum instance count |
| worker | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` | 1 instance by default |
| scheduler | `php artisan schedule:work` | exclusive, so exactly one instance |

Other examples are `horizon` with `php artisan horizon`, `reverb` with `php artisan reverb:start`, and `ssr` with `php artisan inertia:start-ssr`. This one list replaces the worker clusters, managed queues, WebSockets clusters, and SSR switches of Laravel Cloud. A Dockerfile app gets no processes by default, but it can have its own.

Autoscaling adds web instances when the CPU use goes above a target, for example 70% [C]. On `local`, autoscaling adds pods but not machines. On managed Kubernetes, the provider adds machines.

### Give a new Laravel app a database by default

A new Laravel app uses SQLite by default, and it keeps sessions, the cache, and the queue in that database. In a container with no database attached, the pages fail. So the "New app" form selects a new database for each Laravel app by default. The user sees the selection and can remove it [C-new]. The default engine is MySQL [C-new].

For that reason, the first standard database comes in the same step as the first deploy. The other standard services come later [C-new].

### Deploy without downtime

A deployment goes through these steps [C].

1. Opencloud builds the image.
2. Opencloud runs the deploy commands in a one-off Kubernetes job with the new image. The default commands are `php artisan migrate --force` and the Laravel cache commands. The user can change them.
3. If a deploy command fails, the deployment fails, and the old version stays live.
4. Opencloud starts the new version. A new instance gets traffic only after its health check passes. The default health route is `/up` [C-new].
5. Opencloud stops the old instances.
6. Opencloud requests `/` one time. If the response is a server error, the dashboard shows a warning [C-new]. The `/up` route of Laravel does not run the `web` middleware, so `/up` can pass while the pages fail.

The migrations run before the new code gets traffic. So the old code runs against the new schema for a short time. Laravel Cloud has the same behavior [C-new].

The dashboard shows build logs and app logs. It reads them through the Kubernetes API and polls [C].

### Make domains boring with Caddy

Domains are where Coolify failed Samir. Opencloud treats this part with the most care [S], [C]. Caddy is the proxy on each cluster, and Caddy issues and renews the certificates [S]. One part does both jobs, so opencloud needs no separate ingress controller and no cert-manager [C-new].

- The dashboard owns the routing table. It sends the full Caddy config to Caddy through the admin API. Caddy applies a new config completely or rejects it, so a bad change never leaves half a config [C-new].
- Caddy uses on-demand TLS. Before Caddy asks for a certificate, it asks the dashboard if the hostname belongs to an environment. So Caddy never asks for a certificate for an unknown name [C-new].
- For each domain, the dashboard shows the exact DNS record to add and the IP address it must point to [C].
- Opencloud checks DNS before Caddy asks for a certificate. If DNS is wrong, the dashboard says what it found and what it expected [C].
- Opencloud tries again automatically until DNS is correct [C].

Cloudflare in front of a site works, but only when the user wants it [S].

- If DNS shows Cloudflare addresses, the dashboard recognizes the Cloudflare proxy. It does not report the domain as wrong [C-new].
- The owner can add a Cloudflare API token. Then opencloud creates the DNS records itself, and Caddy uses DNS challenges. This gives a wildcard certificate for previews, and it works with the Cloudflare proxy on or off [C-new].
- Without a Cloudflare token, the user adds DNS records by hand, at any DNS provider [C-new].

### Attach services in three ways

An app can get a database, Valkey, or file storage in three ways [S].

1. **Opencloud standards.** The user selects a service in the dashboard, and opencloud runs it in the cluster of the app.
2. **Managed cloud services.** OpenTofu creates the service in the cloud account of the owner, for example a managed Postgres. This comes later, with `created` clusters.
3. **External services.** The user puts the connection data in the env of the environment. This works from day one, because env editing exists.

The standards are these [S], [C].

| Service | Software | How environments share it |
|---|---|---|
| MySQL | MySQL | one server, one database and one user per environment |
| Postgres | Postgres | one server, one database and one user per environment |
| Redis | Valkey | builder decides |
| S3 storage | RustFS, pinned version | one bucket and one access key per environment |

Each standard runs as one instance on a cluster disk [C].

When a user attaches a standard service to an environment, opencloud sets the Laravel env keys [C]. Examples are `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `AWS_ACCESS_KEY_ID`, `AWS_BUCKET`, and `AWS_ENDPOINT`. A value that the user types always wins over a value that opencloud sets.

### Back up and restore by default

A database on one VPS keeps its data on one disk. So backups are on by default [C].

- Opencloud dumps each standard database every night and keeps the last 7 dumps [C-new].
- The dumps go to the local disk until the owner adds an S3 target [C].
- While backups exist only on the local disk, the dashboard shows a warning [C-new].
- When an S3 target exists, opencloud also copies the RustFS buckets to it every night [C-new].
- Opencloud also backs up the SQLite file of the dashboard [C-new].
- A user can restore one database from one backup with a button in the dashboard. This comes in the same step as backups [C-new].
- If the dashboard VPS is lost, the installer can restore a full install on a new VPS from the S3 target and the `APP_KEY`. This comes in a late step [C-new].

The dashboard encrypts secrets with the Laravel `APP_KEY`. A backup of the SQLite file is useless without that key. The dashboard tells the owner to store the key in a safe place [C-new].

### Give each pull request a preview

Previews come after the core loop works [C]. The core loop is install, connect GitHub, deploy, and add a domain.

- A user opens a pull request. Opencloud creates a preview environment, deploys the branch, and posts the preview URL on the pull request [C].
- A user pushes to the branch. Opencloud deploys the preview again [C].
- A user merges or closes the pull request. Opencloud deletes the preview and its database [C].
- The owner adds one wildcard DNS record one time, for example `*.preview.example.com`. Each preview gets an address like `pr-12.preview.example.com` [C]. Caddy gets a certificate for each preview address on demand. With a Cloudflare token, one wildcard certificate covers all previews [C-new].
- Each preview gets a new, empty database on the same database server as production. Opencloud runs the migrations, and the seeders if the user switches them on [C]. A preview never gets a copy of production data [C].
- A preview takes its settings and env from the preview template of the app, never from production [C].
- A preview runs as one small instance with no autoscaling [C].
- Pull requests from forks get no preview by default [C].
- An app has at most 5 previews at the same time by default, to protect the VPS [C-new].

### Teams, roles, and secrets

One install serves one organization. Each user has one role [S], [C]. Production is a protected environment. Only an owner or an admin can change a protected environment [C-new]. An admin can set production secrets, so the design trusts admins with them.

| Right | `owner` | `admin` | `developer` |
|---|---|---|---|
| Manage cloud keys, the GitHub connection, kubeconfigs, and members | yes | no | no |
| Manage apps, services, and domains | yes | yes | no |
| Change the settings, the deploy commands, or any env value of a protected environment | yes | yes | no |
| Change the settings and env values of an unprotected environment, including the preview template | yes | yes | yes |
| Deploy and read logs | yes | yes | yes |

A developer cannot change any value in a protected environment, also not a plain value. Otherwise a developer could point `REDIS_HOST` or `MAIL_HOST` at a server that the developer controls, and the app would send its password there [C-new].

The rules for secrets are these [C].

- Each env value is plain or secret.
- After a user saves a secret, nobody can read it in the dashboard, including the owner. A user can only replace it or delete it.
- Each secret belongs to one environment. Production secrets never go to previews.
- Opencloud gives secrets to the app at runtime only. It masks them in build logs and deploy logs.
- An audit log records who changed which secret, and who deployed what. The audit log never contains a secret value.
- A kubeconfig gives full access to all secrets in a cluster. Only the owner can see it.

Opencloud deploys like Laravel Cloud, with the simplest code [S]. From memory, Laravel Cloud has a push-to-deploy switch on each environment, and it does not check reviews. Each permanent environment deploys from one branch and has an auto-deploy switch, on by default [C-new]. When the switch is on, a push to the branch deploys. When the switch is off, an owner or an admin starts each deploy in the dashboard. Opencloud does not check reviews on GitHub [S].

No dashboard can stop a person who can change the code from reading the secrets that the code gets. So GitHub permissions decide who can reach production secrets through code. A developer who can push to the production branch can read production secrets that way. GitHub gives branch protection to private repositories only on paid plans. The documentation tells the owner this, and it names two fixes [C-new].

- Use a paid GitHub plan, and protect the production branch with a required review.
- Switch auto-deploy off for production. Then an owner or an admin starts each production deploy and can read the changes first.

The dashboard stores secrets encrypted with the Laravel `APP_KEY` [C-new]. On `local`, k3s encrypts Kubernetes secrets at rest [C-new].

### Connect GitHub with a GitHub App

The owner creates a GitHub App from the dashboard with the GitHub manifest flow [C-new]. The app gives access to private repositories, sends webhooks for pushes and pull requests, and lets opencloud post the preview URL. GitHub must reach the dashboard to send webhooks, so the VPS needs a public IP address [C-new].

### Upgrade opencloud safely

Each opencloud release pins the versions of all its parts. The parts are the dashboard image, k3s, Caddy, the registry, RustFS, and the minor versions of MySQL, Postgres, and Valkey [C-new].

The owner upgrades opencloud with one command on the VPS or one button in the dashboard [C].

1. Opencloud copies the SQLite file.
2. Opencloud deploys the new dashboard image and runs the dashboard migrations.
3. If the new dashboard fails its health check, opencloud goes back to the old image and the copied SQLite file [C-new].
4. Opencloud updates the parts on `local` and then on each connected cluster, one cluster at a time. If a cluster fails, that cluster keeps its old parts, and the dashboard shows the error [C-new].

## The core data shape

These are the records in the SQLite database of the dashboard [C-new]. The builder may rename fields. The relations and the enums carry the design.

| Record | Main fields | Notes |
|---|---|---|
| `User` | name, email, password, `role` (`owner`, `admin`, `developer`) | One organization per install, so the role lives on the user. |
| `Invite` | token, `role`, expires_at | |
| `GithubApp` | app_id, private_key (encrypted), webhook_secret (encrypted) | One per install. |
| `ProviderCredential` | `provider` (for example `digitalocean`, `aws`, `hetzner`, `cloudflare`), token (encrypted), checked_at, missing_rights | Owner only. Opencloud fills `missing_rights` from the check on paste. |
| `Cluster` | name, `kind` (`local`, `connected`, `created`), provider, kubeconfig (encrypted), ingress_ip, parts_version, status | `parts_version` shows which release the parts on this cluster come from. |
| `App` | name, repository, cluster_id, `type` (`laravel`, `dockerfile`) | |
| `Environment` | app_id, name, `kind` (`permanent`, `preview`, `preview_template`), is_protected, branch, auto_deploy, pr_number, size, min_instances, max_instances, cpu_target, build_command, deploy_commands, health_path | The `preview_template` environment is never deployed. It holds the settings, the processes, and the env that new previews copy. |
| `Process` | environment_id, name, command, instances, exclusive | One long-running command next to web. `web`, `deploy`, and `command` are reserved names. An exclusive process has exactly one instance. |
| `EnvVar` | environment_id, key, value (encrypted), is_secret, `available_at` (`runtime`, `build`, `both`), `source` (`user`, `service`) | A `user` value wins over a `service` value with the same key. |
| `Deployment` | environment_id, commit_sha, image, `status` (`queued`, `building`, `deploying`, `live`, `failed`, `superseded`), triggered_by, started_at, finished_at | Exactly one `live` deployment per environment. |
| `Domain` | environment_id, hostname, dns_status, `dns_mode` (`manual`, `cloudflare`), certificate_status | |
| `Service` | cluster_id, `kind` (`mysql`, `postgres`, `valkey`, `rustfs`), `source` (`standard`, `managed`), version, status | |
| `ServiceAttachment` | service_id, environment_id, database_name, username, password (encrypted) | Creates the `service` env values. |
| `BackupTarget` | endpoint, bucket, access_key (encrypted), secret_key (encrypted) | Empty means local disk. |
| `Backup` | service_id, location, size, status, created_at | |
| `AuditEvent` | user_id, action, subject, created_at | Never contains a secret value. |

## Prove it works with the release test

"It just works" is a claim, so a test must prove it on each release [C]. The release test runs these steps on a new VPS from a real provider.

1. Run the install command. The test fails if the setup URL does not appear within 5 minutes [C-new]. The 5 minutes is a starting target. The first real measurement sets the real number.
2. Open the setup URL and create the owner.
3. Connect a sample Laravel repository and deploy it with the defaults. Check that a page in the `web` middleware group answers, not only `/up`.
4. Add a domain and check that the site answers on HTTPS with a valid certificate.
5. Attach Valkey and RustFS. Check that the sample app writes and reads a row, a cache key, and a file through the Laravel S3 driver.
6. Push a commit and check that the new version goes live with no failed request during the switch.
7. Push a commit that breaks a migration. Check that the old version stays live.
8. Open a pull request. Check that the preview URL answers and that the preview has no production secret.
9. Close the pull request. Check that the preview and its database are gone.
10. Restore the sample database from a backup and check the restored row.
11. Upgrade opencloud from the previous release. Check that the sample app stays live, the data stays, and the parts on the cluster have the new versions.

Faster tests run on each commit in CI, on a throwaway k3s cluster [B].

## Suggested order of work

The order puts the scaffold first, then the riskiest unknown [C-new]. The implementation plan may change this order.

1. The repository, CI, and the first part of the release test.
2. A throwaway prototype that proves Caddy with on-demand TLS and config from the admin API on k3s and on one managed cluster. Domains are the main promise, so this unknown goes first.
3. The install command, the dashboard on `local`, Caddy, the setup token, and the owner account.
4. GitHub App, builds, the registry, and the deploy of a Laravel app on `local`, with workers, the scheduler, deploy commands, health checks, logs, and the default MySQL database.
5. Domains and HTTPS for apps and for the dashboard.
6. Env values, secrets, protected environments, and the audit log.
7. Opencloud upgrades.
8. The other standard services, the automatic env keys, backups, and restore of one database.
9. Teams, invites, and roles.
10. The provider key guides and the check on paste.
11. Connected clusters, the registry near each cluster, and autoscaling.
12. Preview environments.
13. Cloudflare DNS mode.
14. Created clusters with OpenTofu for the first provider.
15. Full restore of a lost dashboard VPS.
16. Managed cloud services with OpenTofu.

## Left to the builder

- [B] How Caddy runs on Kubernetes, and where Caddy stores certificates when it runs more than one replica.
- [B] The image build tool on `local`, for example BuildKit.
- [B] The registry software, and how images reach the registry near each cluster.
- [B] The PHP runtime in the Laravel image, for example FrankenPHP or nginx with PHP-FPM. Compatibility with ordinary Laravel apps is more important than speed. Octane can be an option.
- [B] How Laravel talks to Kubernetes. Lean: generate manifests in PHP and apply them with `kubectl apply --server-side`, because the same apply gives the same result when it runs again. Use Helm only for third-party parts.
- [B] How environments share Valkey.
- [B] The dashboard port.
- [B] The provider for the release test VPS.
- [B] The Laravel versions that opencloud supports.

## Open items for Samir

- **The name.** "Opencloud" is a working name [S]. "OpenCloud" is already the name of an active open-source project. The Heinlein Group launched it in January 2025 as a fork of ownCloud Infinite Scale. The name also goes into the image name, the install URL, the Kubernetes namespaces, and the labels. Samir must choose the final name before the first public release, because a later rename changes resources on live clusters. The code keeps the name in one constant so that the rename is cheap until then [C-new].
- **The license of opencloud itself.** Examples are MIT, Apache 2.0, and AGPL 3.0. The license decides whether a company can sell a closed, hosted copy.
- **The first provider for `created` clusters.** Claude leans to DigitalOcean. Its managed Kubernetes adds and removes machines automatically, so the OpenTofu module only creates a cluster and returns a kubeconfig. Hetzner is cheaper but has no managed Kubernetes, so opencloud must create the machines, install k3s, and scale the machines itself.

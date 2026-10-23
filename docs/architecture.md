# Opencloud architecture

This document is the contract for the code. The design document `docs/superpowers/specs/2026-10-22-opencloud-design.md` says what opencloud does and why. This document says where each part lives and how the parts talk. When the code and this document disagree, fix one of them in the same commit.

## Repository layout

| Path | Contents |
|---|---|
| `install.sh` | The one-command installer for a fresh Ubuntu 24.04 server. |
| `bin/opencloud` | The CLI that the installer puts on the server, for example `opencloud upgrade`. |
| `platform/` | Kubernetes manifests for the opencloud parts on the `local` cluster. `platform/images/caddy/` holds the Caddy image. |
| `dashboard/` | The Laravel app. It is the dashboard, the API, and the queue worker. |
| `dashboard/Dockerfile` | The image of the dashboard. CI publishes it. |
| `dashboard/resources/docker/` | The Dockerfile template for user Laravel apps. |
| `tofu/` | OpenTofu modules for `created` clusters and managed services. |
| `tests/e2e/` | The release test. It drives a fresh Ubuntu machine end to end. |

## Names

Use these names everywhere: in PHP, in the UI, in Kubernetes labels, and in docs.

| Name | Meaning | Not |
|---|---|---|
| `Application` | One GitHub repository that opencloud deploys. The model is `App\Models\Application`. | `App`, because `App\` is the PHP root namespace. |
| `Environment` | One running copy of an application, for example production or a preview. | |
| `Deployment` | One attempt to put one commit live in one environment. | |
| `Datastore` | A database, Valkey, or RustFS server that opencloud runs. | `Service`, because a Kubernetes `Service` is a different thing. |
| `DatastoreAttachment` | One environment's database, user, bucket, or key on a datastore. | |
| `Cluster` | A Kubernetes cluster that runs environments and datastores. | |
| `ProviderCredential` | An API key for a cloud provider or Cloudflare. | |

## The local cluster

The installer creates this layout on the dashboard VPS. All opencloud parts live in the namespace `opencloud`.

| Part | Kind | Address |
|---|---|---|
| Dashboard | One pod with three containers, `web`, `worker`, and `scheduler`. They share the host path `/var/lib/opencloud/data`, which holds `database.sqlite`. | Service `dashboard.opencloud.svc.cluster.local:8080` |
| Caddy | Deployment `caddy` with a rolling update, Service `caddy` of type `LoadBalancer` with ports 80, 443, and 8000. k3s ServiceLB binds these ports on the host. Certificates and the last config live on the host paths `/var/lib/opencloud/caddy/data` and `/var/lib/opencloud/caddy/config`, so they survive a pod restart. | Admin API on `127.0.0.1:2019` inside the pod only |
| Registry | Deployment `registry` with the image `registry:3`, data on the host path `/var/lib/opencloud/registry`. | Service `registry` with the fixed ClusterIP `10.43.200.200`, port 5000 |
| BuildKit | Deployment `buildkitd`, privileged, state on the host path `/var/lib/opencloud/buildkit`. | Service `buildkitd.opencloud.svc.cluster.local:1234` |

Images have names like `registry.opencloud.svc.cluster.local:5000/<application-id>/<environment-id>:<short-sha>-<deployment-id>`. BuildKit pushes to that name over plain HTTP, because it resolves cluster DNS. The node cannot resolve cluster DNS, so the installer writes `/etc/rancher/k3s/registries.yaml` with a mirror from that name to `http://10.43.200.200:5000`. The registry is never reachable from outside the cluster.

The dashboard runs `kubectl` with its in-cluster service account for `local`. The service account has `cluster-admin` on `local`.

The NetworkPolicies in `platform/networkpolicies.yaml` let only the dashboard reach BuildKit, and only the dashboard and BuildKit reach the registry. The node still pulls through the registry ClusterIP, because kube-router lets traffic from the node itself reach local pods. `tests/e2e/network-policy.sh` proves both.

A release is a tag `v*`. `.github/workflows/release.yml` publishes the dashboard image and the Caddy image (`opencloud-caddy`) for amd64 and arm64 and a release archive with `install.sh`, `platform/`, and `bin/`. `tests/e2e/build-release-archive.sh` builds the archive for the workflow and for the tests. When `install.sh` runs from `curl | bash`, it downloads that archive, checks its SHA-256 sum, extracts it to `/opt/opencloud/<version>`, and runs from there. Without `OPENCLOUD_VERSION`, it asks the GitHub API for the tag of the latest release and installs that tag, so the dashboard always knows its real version.

The installer keeps its state in `/var/lib/opencloud`. The files `APP_KEY` and `setup-token` there have mode 600. The installer copies them into the Secret `dashboard` and never into a manifest file. The k3s options live in `/etc/rancher/k3s/config.yaml`. If `APP_KEY` is missing while the database exists, the installer stops, because a new key cannot read the stored secrets. In restore mode, the installer brings both back from the backup bucket. The section Restore describes it.

The dashboard is at `http://<server-ip>:8000`. Caddy forwards port 8000 to the dashboard Service. The owner can also give the dashboard a domain in Settings. After its DNS check passes, Caddy serves the dashboard on that domain over HTTPS, and the dashboard uses the domain as its root URL. The queue workers restart then, because they read the root URL when they boot.

## How the dashboard talks to a cluster

`App\Kube\Kubectl` is the only class that runs `kubectl`. It runs the binary with Symfony Process. For `local` it uses the in-cluster config. For other clusters it writes the decrypted kubeconfig to a temporary file for the duration of one call. Tests bind `App\Kube\FakeKubectl`, which records each call and returns scripted output.

Manifests are plain PHP arrays that pure classes in `App\Kube\Manifests\` build from models. `Kubectl::apply()` sends them with `kubectl apply --server-side --force-conflicts --field-manager=opencloud`. The same apply gives the same result when it runs again, so each sync sends the full desired state.

Each environment gets its own namespace, `oc-env-<environment-id>`. Datastores live in the namespace `oc-datastores`.

## How the dashboard talks to Caddy

`App\Caddy\CaddyConfig` is a pure class. It builds the full Caddy JSON config for one cluster from the database. `App\Caddy\CaddySync` sends that JSON to the Caddy pod with `kubectl exec`. Inside the pod it posts the JSON to `http://127.0.0.1:2019/load`. Use `127.0.0.1`, not `localhost`. Busybox resolves `localhost` to `::1`, and Caddy does not listen there. Caddy applies a config completely or rejects it.

Caddy runs with `--resume`, so it keeps the last config when it restarts. The scheduler also sends the full config every five minutes, so drift repairs itself.

A domain goes into the Caddy config only after its DNS check passes. Caddy then gets the certificate with its normal automatic HTTPS. Opencloud does not use on-demand TLS, because the dashboard always knows each hostname before Caddy needs it. The Caddy image is the official Caddy of the same version plus the module `github.com/caddy-dns/cloudflare`, built with xcaddy in `platform/images/caddy/Dockerfile`. `install.sh` imports it from `OPENCLOUD_CADDY_IMAGE_ARCHIVE` or pulls it from `ghcr.io`.

A domain in `cloudflare` DNS mode needs the Cloudflare key. Opencloud creates the A record through the Cloudflare API, and `CaddyConfig` puts the hostname under a TLS automation policy with the Cloudflare DNS challenge and the stored token. That policy comes before the policy without subjects. Removing the domain deletes the record that opencloud created. The certificate issuer comes from the setting `OPENCLOUD_TLS_ISSUER`. The value `acme` uses Let's Encrypt. The value `internal` uses the local Caddy CA, for tests.

## Provider keys

`App\CloudProviders\Providers` is the one table of provider guides: DigitalOcean, Hetzner, AWS, and Cloudflare. Each `ProviderGuide` has the key page URL, the rights to select, an optional CLI command, and a check class. A check makes read-only API calls and returns a `CheckResult` with the status `ok`, `invalid`, or `missing_rights`. The AWS check signs its requests with `AwsSignatureV4`. The settings page "Provider keys" is for the owner only. The command `opencloud:check-provider-links` requests each key page, and a weekly workflow runs it.

## OpenTofu

`App\Tofu\Runner` is the only class that runs `tofu`. A `Stack` names a module under `tofu/`, a work folder `<data_path>/tofu/<kind>-<id>`, the module variables, and the provider credentials. Each run copies the module into the work folder and writes the variables to `opencloud.tfvars.json` with mode 600. The token goes into the process env only, for example `DIGITALOCEAN_TOKEN`. The state stays in the work folder, and `TF_ENCRYPTION` always turns on state and plan encryption with a PBKDF2 passphrase from `APP_KEY`. So the passphrase is never on disk, and a new `APP_KEY` cannot read the old state. Each output line goes through `SecretMasker` into the `tofu_log` column, which the owner sees in the UI. The outputs never go into the log. Tests swap `tofu` with `Process::fake()`. `StateEncryptionTest` runs the real binary when `OPENCLOUD_TEST_TOFU_BINARY` is set.

The image holds a pinned `tofu` and the modules in `/opt/opencloud/tofu`. Build it with `--build-context tofu=tofu`. The `.terraform.lock.hcl` of each module pins the provider hashes.

- `tofu/digitalocean/cluster` creates a DOKS cluster with one autoscaling node pool. It uses the registry of the account and creates one when the account has none. The registry has `destroy = false`, so a destroy leaves it for other clusters. The module outputs a kubeconfig with a service account token, because the kubeconfig of DigitalOcean expires after seven days, and a credential for the registry only.
- `tofu/digitalocean/database` creates a managed MySQL or Postgres with one node, a database, and a user. Its firewall lets in only the Kubernetes cluster.

`CreateCluster` stores a `created` cluster with the status `provisioning`. `ProvisionCluster` applies the cluster module, stores the kubeconfig and the registry, and dispatches `InstallClusterParts`. `DestroyCluster` refuses while applications or managed databases use the cluster, and `TearDownCluster` runs `tofu destroy` and removes the record. A managed attachment has a managed `Datastore` of its own. `ProvisionManagedDatabase` applies the database module and writes the same Laravel env values as the standard engine. Detach of a managed attachment runs `TearDownManagedDatabase`, which destroys the database and its data. The OpenTofu jobs run on the `builds` queue, because a cluster or a database takes minutes. A failed run keeps the record with the error, and the next run converges.

## Builds

`App\Build\DockerfileTemplate` is a pure class. It reads `composer.json` and `package.json` and returns the Dockerfile text for a Laravel app. The template lives in `dashboard/resources/docker/laravel.Dockerfile`. The image runs FrankenPHP on port 8080 and serves `/app/public`. BuildKit cache mounts keep the Composer and npm downloads between builds. The web process runs `php artisan optimize` when it starts, because the config cache must see the runtime env.

`App\Build\Builder` clones the commit into a temporary folder, writes the Dockerfile, and runs `buildctl` against BuildKit. Build-time env values go in as BuildKit secrets, never as build arguments.

`App\Support\SecretMasker` replaces each secret value with `********` in each log line before the line goes into the database.

## Deployments

`App\Enums\DeploymentStatus` is a state machine. Its `canMoveTo()` method lists the allowed moves:

- `queued` to `building`, `failed`
- `building` to `deploying`, `failed`
- `deploying` to `live`, `failed`
- `live` to `superseded`

`App\Jobs\RunDeployment` runs one deployment from start to end. It builds, runs the deploy commands in a Kubernetes Job, applies the manifests, waits for the rollout, and syncs Caddy. If a step fails, the deployment becomes `failed`, and the old live deployment stays live.

## Actions are the seam between the UI and the engine

Controllers never touch Kubernetes, Caddy, or BuildKit. They validate the request, check the policy, and call one action class in `app/Actions`. Each action writes the database records, records an audit event, and dispatches jobs. The engine code in `app/Kube`, `app/Caddy`, `app/Build`, `app/Datastores`, and `app/Jobs` runs behind the actions.

| Action | Signature | What it does |
|---|---|---|
| `CreateApplication` | `handle(User $by, array $data): Application` | Creates the application, a protected `production` environment, the `preview_template` environment, the default MySQL attachment for a Laravel app unless `$data['database']` is `none`, the domains, and the env values. Then it starts the first deployment. |
| `StartDeployment` | `handle(Environment $environment, ?User $by, ?string $commitSha = null): Deployment` | Creates a `queued` deployment and dispatches `RunDeployment`. A null SHA means the head of the environment branch. |
| `SetEnvVar` | `handle(Environment $environment, User $by, string $key, ?string $value, bool $isSecret, EnvVarAvailability $availableAt): EnvVar` | Creates or replaces one user value. A null value on an existing secret keeps the old secret value. |
| `DeleteEnvVar` | `handle(EnvVar $envVar, User $by): void` | Deletes one user value. |
| `AddDomain` | `handle(Environment $environment, User $by, string $hostname, bool $manageDns = false, bool $proxied = false): Domain` | Stores the domain and dispatches `CheckDomain`. With `$manageDns`, it first creates or updates the Cloudflare A record. |
| `RemoveDomain` | `handle(Domain $domain, User $by): void` | Deletes the Cloudflare record that opencloud created, deletes the domain, and syncs Caddy. |
| `AttachDatastore` | `handle(Environment $environment, ?User $by, DatastoreKind $kind, DatastoreSource $source = Standard): DatastoreAttachment` | Finds or creates the standard datastore of that kind on the cluster, creates the attachment, and dispatches its provisioning. With the managed source, it creates a managed datastore for the environment and dispatches `ProvisionManagedDatabase`. |
| `DetachDatastore` | `handle(DatastoreAttachment $attachment, User $by): void` | Removes the attachment and its `datastore` env values. For a managed attachment, it removes the env values and dispatches `TearDownManagedDatabase`. |
| `CreateCluster` | `handle(User $by, array $data): Cluster` | Stores a `created` DigitalOcean cluster and dispatches `ProvisionCluster`. It needs a stored DigitalOcean key. |
| `DestroyCluster` | `handle(Cluster $cluster, User $by): void` | Refuses while applications or managed databases use the cluster. Then it dispatches `TearDownCluster`. |
| `StoreProviderCredential` | `handle(User $by, string $provider, string $token): CheckResult` | Checks the key with the provider. It stores the key encrypted when the check passes or only optional rights are missing. The audit event never holds the key. |
| `DeleteProviderCredential` | `handle(ProviderCredential $credential, User $by): void` | Deletes the key. |
| `ConnectCluster` | `handle(User $by, array $data): Cluster` | Checks the kubeconfig with `kubectl version` and `kubectl auth can-i`, stores the cluster and its registry encrypted, and dispatches `InstallClusterParts`. |
| `ReinstallClusterParts` | `handle(Cluster $cluster, ?User $by): void` | Applies the opencloud parts on a connected cluster again. A null user means opencloud itself, after an upgrade. |
| `DisconnectCluster` | `handle(Cluster $cluster, User $by, bool $removeParts): void` | Refuses while applications or datastores use the cluster. It can remove the opencloud namespaces. |
| `ConnectGitHubApp` | `handle(User $by, string $code): GithubApp` | Exchanges the code of the manifest flow and stores the GitHub App. It replaces the old app. |
| `UpdatePreviewSettings` | `handle(Application $application, User $by, array $settings): void` | Stores `previews_enabled`, `allow_forks`, and `preview_domain`. |
| `ReadLogs` | `handle(Environment $environment, string $process, int $lines = 200): string` | Returns recent log lines of `web`, `worker`, or `scheduler`, with secrets masked. It records no audit event, because the UI polls it. |

## Connected clusters

The owner connects a cluster with its kubeconfig and the address and credentials of a registry near that cluster. `InstallClusterParts` puts Caddy in the namespace `opencloud` with a Service of type `LoadBalancer` and its state on a PVC, and creates the namespace `oc-datastores`. It stores the load balancer address as the ingress IP. Opencloud changes nothing outside its own namespaces, so a cluster that runs other software also works.

For an application on a connected cluster, `RunDeployment` copies the built image from the local registry to the cluster's registry with crane. The environment namespace gets the pull secret `opencloud-registry`, and the pods use the copied image. So a connected cluster can start new pods while the dashboard server is down. The scheduler syncs Caddy only on clusters with the status `ready`.

## Upgrades

`sudo opencloud upgrade [version]` upgrades the server. Without a version, it asks the GitHub releases API of `OPENCLOUD_REPO` for the latest release. It refuses an older release unless you add `--force`. A pre-release such as `v1.2.0-rc.1` is older than `v1.2.0`. Each step prints one line.

1. Download the release archive and check its SHA-256 sum, like the installer. `OPENCLOUD_RELEASE_URL` replaces the GitHub download URL for tests. Nothing has changed yet.
2. Copy the database with `php artisan opencloud:copy-database` in the running dashboard. The command uses `VACUUM INTO`. The copy is `/var/lib/opencloud/data/upgrade-<from>-to-<to>-<time>.sqlite`.
3. Record the images and the rollout revisions of the deployments `dashboard` and `caddy`.
4. Run `install.sh` of the new release with the target version. The upgrade passes on the TLS issuer, the public IP, and the repository from the env of the running dashboard. The image archive variables of the installer pass through. The installer log is `/var/log/opencloud/upgrade-<from>-to-<to>-<time>.log`.
5. Wait for both rollouts and for `/up` through Caddy on port 8000.

If step 4 or 5 fails, the upgrade goes back to the old release. If the dashboard changed, the upgrade scales it to zero. It deletes the write-ahead log and puts back the database copy. Then it runs `kubectl rollout undo` to the recorded revision and scales the dashboard to one.

The upgrade undoes a changed Caddy the same way. Then it waits for `/up` and checks the recorded images. After a way back, the command exits 1.

Caddy uses a rolling update with a 10 second `preStop` sleep, because each release has its own Caddy image tag. The sites keep answering while Caddy changes.

When the new dashboard starts, `opencloud:bootstrap` updates the other clusters. It runs `ReinstallClusterParts` for each cluster that is not `local` and has parts from another release. A cluster with the status `pending` or `installing` keeps its state.

The scheduler runs `CheckLatestRelease` once a day. It caches the tag of the latest release for three days, and a failed request changes nothing. The sidebar shows the running version to each user. When the cached release is newer, it shows the owner the command to run.

`tests/e2e/upgrade.sh` proves an upgrade with no failed request to a live application, and the way back from a release whose dashboard fails.

## Queues

The dashboard pod runs two queue workers. The `worker` container serves the `default` queue with a 600 second timeout. The `builder` container serves the `builds` queue with a 3600 second timeout. Long jobs go on `builds`: `RunDeployment`, `BackupDatabase`, `RestoreDatabase`, `RunRestore`, and the OpenTofu jobs `ProvisionCluster`, `TearDownCluster`, `ProvisionManagedDatabase`, and `TearDownManagedDatabase`. `RunRestore` provisions the datastores itself and waits for no queued job, so it never blocks behind the deployments that it starts. Short jobs stay on `default`: `SyncCaddy`, `CheckDomain`, `ProvisionDatastoreAttachment`, `DeployPush`, `SyncPreview`, and `PostPreviewComment`. A deployment waits for its datastores, so datastore provisioning must never queue behind a deployment.

## Datastores

Each kind has one engine in `app/Datastores`, and `Engines::for()` picks it. MySQL and Postgres run one server per cluster. Each environment gets its own database and user with rights on that database only. RustFS runs one server per cluster with a sidecar container `rc`. Each environment gets a bucket, an IAM user, and a policy that allows only that bucket. Valkey runs one small instance per attachment. Attach writes the Laravel env values with source `datastore`. Detach removes the user or key and keeps the data. `DetachDatastore::destroy()` also removes the data, for previews.

Each deployment has its own env Secret, `env-<deployment-id>`. A failed deploy cannot change the env of the pods that still run. After a deployment goes live, the job keeps the live Secret and the two newest, and deletes the rest.

Backups run every night at 03:00. A dump streams through `kubectl exec` into a gzip file under `<data_path>/backups`, or into the S3 backup target when one exists. The newest 7 dumps per attachment stay. A restore first backs up the current state, then empties the database and streams the dump back.

## Restore

A restore puts a lost install back on a new server. The code lives in `app/Restore`.

1. The owner runs `install.sh` with `OPENCLOUD_RESTORE_FILE`. The file holds the settings of the backup bucket and the saved `APP_KEY`. The installer refuses restore mode when `database.sqlite` exists without `restore.json`.
2. Before the dashboard starts, the installer runs the Job in `platform/restore.yaml` with the dashboard image. The Job reads the file from the host path, so its values never go into a manifest, a Secret, a command line, or a log. It runs `opencloud:fetch-dashboard-backup`. `DashboardBackup` takes the newest `backups/dashboard/*.sqlite.gz` and decrypts one encrypted column with the `APP_KEY`. Only then does it write the `APP_KEY` file and `database.sqlite`. It empties `jobs`, `cache`, and `cache_locks`, because no worker of the lost server lives on. Then `RestoreState::begin()` writes `restore.json` in the data folder with the start time.
3. After the dashboard starts, the bootstrap command stores the new IP as the ingress IP of `local`. The installer runs `opencloud:restore`, which queues `RunRestore` on `builds`.
4. `RestoreInstall` runs the steps of the enum `RestoreStep` in order: retire the old deployments of `local`, provision each attachment again with its stored names and passwords, restore each MySQL and Postgres database, copy the RustFS buckets back, deploy the live commit of each permanent environment, check each domain again, and sync Caddy. `restore.json` records the status of each step. A run skips the done steps, so `opencloud restore` resumes a failed restore.

A database comes back from its newest dump file in the bucket from before the start time. A nightly backup on the new server can dump an empty database during the restore, so later dumps do not count. A connected cluster kept running, so the restore leaves its records alone.

## GitHub

`App\GitHub` is the boundary to GitHub. One `GithubApp` row holds the app of the install. The owner creates it on the page `/settings/github` with the manifest flow. `GitHubClient` signs an RS256 app token with `AppJwt`. It finds the installation of a repository and keeps the installation token encrypted in the cache until one minute before it expires. Tests swap every call with `Http::fake()`.

`App\Build\Git` is the only class that runs `git` against a remote. For a github.com repository with the app installed, it uses `https://x-access-token:<token>@github.com/<owner>/<repo>.git`. It fetches the URL directly, so `.git` never stores the token. It masks the token in the message of a failed command. Without a token it uses the plain URL, so public repositories work without the app.

`POST /webhooks/github` checks `X-Hub-Signature-256` with `hash_equals` first and answers 401 on a bad signature. It parses the payload into `PushEvent` or `PullRequestEvent`, queues one job, and answers 202. A repeated `X-GitHub-Delivery` id does nothing.

## Previews

A `push` queues `DeployPush`. It deploys the pushed commit to each `permanent` environment of the branch with `auto_deploy` on.

A `pull_request` queues `SyncPreview`. The actions `opened`, `reopened`, and `synchronize` run `App\Previews\OpenPreview`. The action `closed` runs `App\Previews\ClosePreview`.

- `OpenPreview` skips the pull request when previews are off, or when it comes from a fork and `allow_forks` is off. At `opencloud.max_previews` previews, it comments on the pull request and creates nothing. A new preview is the environment `pr-<number>`. It copies the settings and the `user` env values of the preview template and runs one instance. It gets its own `APP_KEY` when the template has none, and it attaches the datastore kinds of production. Its domain is `pr-<number>.<preview_domain>`, or `pr-<number>-<application slug>.<ingress ip>.sslip.io` without a preview domain. Then it deploys the head commit.
- `ClosePreview` waits while a deployment of the preview builds or rolls out. Then it deletes the namespace and calls `DetachDatastore::destroy()` for each attachment. It deletes the environment and its records, and it syncs Caddy.
- `DeploymentObserver` queues `PostPreviewComment` when a preview deployment moves to `live`. The job creates one comment with the preview URL and keeps its id in `pr_comment_id`. Later deployments update that comment. A failed call only writes a warning to the log.

## Roles

`App\Enums\Role` holds the matrix of rights in one place. Policies ask `Role::allows(Ability)` and never compare role names directly. A developer has no write rights on a protected environment.

## Tests

- Pure classes get unit tests that assert literal expected values.
- Jobs and controllers get feature tests with `FakeKubectl`.
- The release test in `tests/e2e/` drives a fresh Ubuntu machine. It is the proof of "it just works".

Run the dashboard tests with `XDEBUG_MODE=off php artisan test` in `dashboard/`.

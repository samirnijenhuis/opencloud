# Opencloud architecture

This document is the contract for the code. The design document `docs/superpowers/specs/2026-10-22-opencloud-design.md` says what opencloud does and why. This document says where each part lives and how the parts talk. When the code and this document disagree, fix one of them in the same commit.

## Repository layout

| Path | Contents |
|---|---|
| `install.sh` | The one-command installer for a fresh Ubuntu 24.04 server. |
| `bin/opencloud` | The CLI that the installer puts on the server, for example `opencloud upgrade`. |
| `platform/` | Kubernetes manifests for the opencloud parts on the `local` cluster, and the Caddy image. |
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
| Caddy | Deployment `caddy`, Service `caddy` of type `LoadBalancer` with ports 80, 443, and 8000. k3s ServiceLB binds these ports on the host. | Admin API on `127.0.0.1:2019` inside the pod only |
| Registry | Deployment `registry` with the image `registry:3`, data on the host path `/var/lib/opencloud/registry`. | Service `registry` with the fixed ClusterIP `10.43.200.200`, port 5000 |
| BuildKit | Deployment `buildkitd`, privileged, state on the host path `/var/lib/opencloud/buildkit`. | Service `buildkitd.opencloud.svc.cluster.local:1234` |

Images have names like `registry.opencloud.svc.cluster.local:5000/<application-id>/<environment-id>:<commit-sha>`. BuildKit pushes to that name over plain HTTP, because it resolves cluster DNS. The node cannot resolve cluster DNS, so the installer writes `/etc/rancher/k3s/registries.yaml` with a mirror from that name to `http://10.43.200.200:5000`. The registry is never reachable from outside the cluster.

The dashboard runs `kubectl` with its in-cluster service account for `local`. The service account has `cluster-admin` on `local`.

The dashboard is at `http://<server-ip>:8000`. Caddy forwards port 8000 to the dashboard Service.

## How the dashboard talks to a cluster

`App\Kube\Kubectl` is the only class that runs `kubectl`. It runs the binary with Symfony Process. For `local` it uses the in-cluster config. For other clusters it writes the decrypted kubeconfig to a temporary file for the duration of one call. Tests bind `App\Kube\FakeKubectl`, which records each call and returns scripted output.

Manifests are plain PHP arrays that pure classes in `App\Kube\Manifests\` build from models. `Kubectl::apply()` sends them with `kubectl apply --server-side --force-conflicts --field-manager=opencloud`. The same apply gives the same result when it runs again, so each sync sends the full desired state.

Each environment gets its own namespace, `oc-env-<environment-id>`. Datastores live in the namespace `oc-datastores`.

## How the dashboard talks to Caddy

`App\Caddy\CaddyConfig` is a pure class. It builds the full Caddy JSON config for one cluster from the database. `App\Caddy\CaddySync` sends that JSON to the Caddy pod with `kubectl exec`. Inside the pod it posts the JSON to `http://127.0.0.1:2019/load`. Use `127.0.0.1`, not `localhost`. Busybox resolves `localhost` to `::1`, and Caddy does not listen there. Caddy applies a config completely or rejects it.

Caddy runs with `--resume`, so it keeps the last config when it restarts. The scheduler also sends the full config every five minutes, so drift repairs itself.

A domain goes into the Caddy config only after its DNS check passes. Caddy then gets the certificate with its normal automatic HTTPS. Opencloud does not use on-demand TLS, because the dashboard always knows each hostname before Caddy needs it. The certificate issuer comes from the setting `OPENCLOUD_TLS_ISSUER`. The value `acme` uses Let's Encrypt. The value `internal` uses the local Caddy CA, for tests.

## Builds

`App\Build\DockerfileTemplate` is a pure class. It reads `composer.json` and `package.json` and returns the Dockerfile text for a Laravel app. The template lives in `dashboard/resources/docker/laravel.Dockerfile`. The image runs FrankenPHP on port 8080 and serves `/app/public`.

`App\Build\Builder` clones the commit into a temporary folder, writes the Dockerfile, and runs `buildctl` against BuildKit. Build-time env values go in as BuildKit secrets, never as build arguments.

`App\Support\SecretMasker` replaces each secret value with `********` in each log line before the line goes into the database.

## Deployments

`App\Enums\DeploymentStatus` is a state machine. Its `canMoveTo()` method lists the allowed moves:

- `queued` to `building`, `failed`
- `building` to `deploying`, `failed`
- `deploying` to `live`, `failed`
- `live` to `superseded`

`App\Jobs\RunDeployment` runs one deployment from start to end. It builds, runs the deploy commands in a Kubernetes Job, applies the manifests, waits for the rollout, and syncs Caddy. If a step fails, the deployment becomes `failed`, and the old live deployment stays live.

## Roles

`App\Enums\Role` holds the matrix of rights in one place. Policies ask `Role::allows(Ability)` and never compare role names directly. A developer has no write rights on a protected environment.

## Tests

- Pure classes get unit tests that assert literal expected values.
- Jobs and controllers get feature tests with `FakeKubectl`.
- The release test in `tests/e2e/` drives a fresh Ubuntu machine. It is the proof of "it just works".

Run the dashboard tests with `XDEBUG_MODE=off php artisan test` in `dashboard/`.

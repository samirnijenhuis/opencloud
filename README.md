# opencloud

Opencloud is a self-hosted platform for Laravel apps. You install it on your own server with one command. You then connect a GitHub repository in its dashboard, and opencloud builds and deploys the app on Kubernetes that you control. You use your own servers, your own cloud accounts, and your own API keys.

"opencloud" is a working name. The final name comes before the first public release.

## What you get

- One command installs k3s, the dashboard, Caddy, a private image registry, and BuildKit on an Ubuntu 24.04 server.
- Push to deploy, and a preview environment for each pull request.
- Laravel apps build with no Dockerfile. Other apps build from the Dockerfile in their repository.
- Domains with automatic HTTPS from Caddy. Opencloud shows the exact DNS record to add and checks it for you. An optional Cloudflare mode creates the records itself.
- MySQL, Postgres, Valkey, and RustFS for each environment. Opencloud sets the Laravel env values for you.
- Nightly database backups to the server disk or to an S3 bucket, and a restore button.
- Deploys with no downtime. A failed deploy keeps the old version live.
- Autoscaling. You can also connect any other Kubernetes cluster, managed or not, with its kubeconfig.
- Teams with the roles owner, admin, and developer. Secrets cannot be read back, and previews never get production secrets.

## Requirements

- A server with Ubuntu 24.04, at least 2 vCPU and 4 GB RAM, and a public IP address.
- Open ports 80, 443, and 8000.

## Install

Run this command on the server:

```bash
curl -fsSL https://github.com/opencloud-dev/opencloud/releases/latest/download/install.sh | sudo bash
```

The installer checks the server first and stops with a clear message if something is missing. At the end it prints a setup URL. Open that URL and create the owner account. Only a person with the setup URL can create the owner.

The project has no published release yet, so this command does not work today. Until the first release, install from source.

### Install from source

1. On a computer with Docker, build the two images for the CPU type of the server (`linux/amd64` or `linux/arm64`):

   ```bash
   git clone <this repository> opencloud && cd opencloud
   docker build --platform linux/amd64 --build-context tofu=tofu -t ghcr.io/opencloud-dev/opencloud:dev dashboard
   docker build --platform linux/amd64 -t ghcr.io/opencloud-dev/opencloud-caddy:dev platform/images/caddy
   docker save -o opencloud.tar ghcr.io/opencloud-dev/opencloud:dev
   docker save -o caddy.tar ghcr.io/opencloud-dev/opencloud-caddy:dev
   ```

2. Copy the `opencloud` folder, with the two archives in it, to the server.
3. On the server, run the installer from that folder:

   ```bash
   sudo OPENCLOUD_VERSION=dev OPENCLOUD_IMAGE_ARCHIVE=$PWD/opencloud.tar OPENCLOUD_CADDY_IMAGE_ARCHIVE=$PWD/caddy.tar ./install.sh
   ```

The installer keeps its state in `/var/lib/opencloud`. Keep a copy of `/var/lib/opencloud/APP_KEY` in a safe place. Without it, the dashboard cannot read its encrypted data.

## First steps

1. Open the setup URL and create the owner account.
2. Open **Settings > GitHub** and create the GitHub App. GitHub must be able to reach the dashboard for this step and for webhooks.
3. Open **Applications > New application**. A repository is enough. All other fields have defaults that work.
4. Add a domain. The dashboard shows the A record to add at your DNS provider.
5. Open **Settings > Members** to invite people, and **Settings > Backups** to add an S3 bucket for backups.

## Commands on the server

| Command | What it does |
|---|---|
| `sudo opencloud status` | Shows the opencloud pods. |
| `sudo opencloud logs [web\|worker\|builder\|scheduler\|caddy]` | Shows the logs of one part. |
| `sudo opencloud setup-url` | Prints the setup URL, or the dashboard address after setup. |
| `sudo opencloud restore-status` | Shows the progress of a restore. |
| `sudo opencloud restore` | Runs the steps of a restore that are not done, for example after you fix a failed step. |

## Restore a lost server

You can restore the whole install on a new server when the old server is lost. You need an S3 backup target in **Settings > Backups** and the saved `APP_KEY`. The restore uses the newest dashboard copy and the newest dump of each database in the bucket.

1. Start a new Ubuntu 24.04 server.
2. Write the file `/root/opencloud-restore.env` with the settings of the backup target and the `APP_KEY`:

   ```bash
   BACKUP_ENDPOINT=https://s3.example.com
   BACKUP_REGION=us-east-1
   BACKUP_BUCKET=opencloud-backups
   BACKUP_ACCESS_KEY=...
   BACKUP_SECRET_KEY=...
   BACKUP_PATH_STYLE=true
   APP_KEY=base64:...
   ```

3. Make the file readable for root only: `chmod 600 /root/opencloud-restore.env`.
4. Run the installer in restore mode:

   ```bash
   curl -fsSL https://github.com/opencloud-dev/opencloud/releases/latest/download/install.sh | sudo OPENCLOUD_RESTORE_FILE=/root/opencloud-restore.env bash
   ```

5. Follow the restore with `sudo opencloud restore-status`. The restore provisions the datastores again, restores the databases and the RustFS buckets, and deploys each environment that was live.
6. Point the DNS records of your domains at the new server. A domain in Cloudflare DNS mode gets its new record from opencloud.
7. Delete the restore file, or keep it in a safe place.

The installer refuses restore mode on a server that already has a dashboard database. Connected clusters keep running during the loss, and the restored dashboard reconnects to them.

## How it works

The installer turns the server into a one-node Kubernetes cluster with k3s. The dashboard is a Laravel app with SQLite that runs in that cluster. It builds images with BuildKit, stores them in a private registry, and applies Kubernetes manifests with `kubectl`. Caddy routes each domain and gets its certificates. [docs/architecture.md](docs/architecture.md) describes each part. [The design document](docs/superpowers/specs/2026-10-22-opencloud-design.md) explains why opencloud works this way.

## Development

Run the dashboard tests in `dashboard/`:

```bash
composer install && npm ci && npm run build
XDEBUG_MODE=off php artisan test
```

The tests in `tests/e2e/` install opencloud on new Ubuntu machines. They need macOS with [OrbStack](https://orbstack.dev).

| Script | What it proves |
|---|---|
| `tests/e2e/fresh-machine.sh` | The installer works on a new machine and on a second run, the registry and the network policies work. |
| `tests/e2e/curl-install.sh` | The `curl \| bash` install works from a release archive. |
| `tests/e2e/release-test.sh` | The full flow: setup, a new application from a public repository, HTTPS, secrets, a deploy with no downtime, and a failed deploy that keeps the old version live. |

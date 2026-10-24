# Run opencloud on your Mac

This guide runs opencloud on your Mac, built from your checkout. One script creates a Linux machine in OrbStack, builds opencloud, and runs the real installer on that machine. You then use the dashboard in your browser exactly as on a server.

Expect about 3 to 10 minutes until the dashboard answers. In a test with a warm Docker cache it took 2 minutes and 18 seconds. The first app build then takes 10 to 20 minutes, because it downloads every Composer and npm package once.

## Before you start

- A Mac with Apple silicon or Intel.
- [OrbStack](https://orbstack.dev). Install it and open it once. OrbStack also gives you Docker.
- About 8 GB of free memory and 20 GB of free disk space.
- This repository on your Mac.

## Start opencloud

1. Open a terminal in the repository folder.
2. Run the script:

   ```bash
   ./scripts/try-on-mac.sh
   ```

   The script builds the dashboard image and the Caddy image, creates the OrbStack machine `opencloud` with Ubuntu 24.04, and installs opencloud on it.

3. Wait for the end. The script shows one line for each step, and the details go to a log file on the machine. The end looks like this:

   ```
   Opencloud is running.
   Setup URL: http://192.168.139.51:8000/setup/77fdfaf7d493...

   ────────────────────────────────────────────────────────────
   Opencloud runs on the OrbStack machine opencloud.

   Next steps
     1. Open the setup URL above in your browser and create the owner account.
     2. Go to Applications > New application and enter:
          Repository  laravel/laravel   (leave the branch empty)
          Domain      myapp.192.168.139.51.sslip.io
   ...
   ```

   Your IP address is different. Keep the terminal open, because you need the domain later.

To use another machine name, set `OPENCLOUD_MACHINE`, for example `OPENCLOUD_MACHINE=opencloud-test ./scripts/try-on-mac.sh`.

## Create the owner account

1. Open the setup URL in your browser. The page "Create the owner account" opens.
2. Enter your name, your email address, and a password.
3. Click **Create owner account**.

You see the dashboard. The setup URL now gives "404", because only one person can create the owner account.

## Deploy your first app

1. Click **Applications**, then **New application**.
2. In **Repository**, type `laravel/laravel`. Leave the branch empty. Opencloud then uses the default branch of the repository.
3. In **Domain**, type the domain that the script printed, for example `myapp.192.168.139.51.sslip.io`.
4. Keep all other fields. **Database** stays on MySQL.
5. Click **Create and deploy**.
6. Open the **Deployments** tab and click the deployment. The log follows the build.

   The first build downloads all packages. When the log shows "The deployment is live.", the app runs.

7. Open `https://myapp.<your ip>.sslip.io` in your browser.

Your browser warns that the certificate is not trusted. Let's Encrypt cannot reach a machine on your Mac, so Caddy signs the certificate with its own local CA. Accept the warning for this site, or trust the CA as the section "Trust the local certificate" describes. You then see the Laravel welcome page.

`sslip.io` is a public DNS service that resolves `myapp.192.168.139.51.sslip.io` to `192.168.139.51`. So you need no DNS record for local tests.

## Try more

- **Environment values.** Open the **Settings** tab, then **Environment values**. Add a value, and mark it as secret. After you save it, nobody can read it in the dashboard. Click **Deploy** to apply it.
- **Resources.** On the **Environment** tab, click **Add resource** and select Valkey. Then deploy again. The app gets the `REDIS_*` values.
- **Commands.** Open the **Commands** tab and run `php artisan about`. The output shows while the command runs.
- **Logs.** Open the **Logs** tab to read the web, worker, and scheduler logs.
- **Team.** Open **Settings > Members** and create an invite link with a role.
- **Your own repository.** Use any public repository with a Laravel app or a Dockerfile.

Some features need GitHub to reach your dashboard, and a machine on your Mac has no public address. These are push to deploy, private repositories through the GitHub App, and pull request previews. Use the **Deploy** button instead.

## Update to your latest code

Run the script again:

```bash
./scripts/try-on-mac.sh
```

The script builds your current code and installs it on the same machine. Your owner account, your apps, and their databases stay. A second run takes about one minute when only a little code changed.

## Stop or delete the machine

| Command | What it does |
|---|---|
| `orb stop opencloud` | Stops the machine. Your data stays. |
| `orb start opencloud` | Starts the machine again. Opencloud starts with it. |
| `orb delete -f opencloud` | Deletes the machine and all its data. |

In a test, the machine kept its IP address after `orb stop` and `orb start`. The dashboard and the app answered again within a minute.

## When something goes wrong

| Problem | What to do |
|---|---|
| The script stops with an error | Read the message. The build logs are in `.try/build.log` and `.try/build-caddy.log`. The installer output is in `.try/install.log`, and the details of k3s and kubectl are in `/var/log/opencloud-install.log` on the machine: `orb -m opencloud sudo cat /var/log/opencloud-install.log`. |
| The dashboard does not answer | Run `orb -m opencloud sudo opencloud status` and check that all pods are `Running`. |
| A part shows errors | Run `orb -m opencloud sudo opencloud logs web`. Replace `web` with `worker`, `builder`, `scheduler`, or `caddy`. |
| The first build is slow | The build downloads every package once. A slow network makes npm wait and try again. Later builds use the cache and take seconds. |
| "We could not find this repository and branch" | Check the repository name. Leave the branch empty, or type a branch that exists. |
| The domain shows "Wrong address" | Use the exact domain that the script printed. It contains the IP address of the machine. |

## Trust the local certificate

This step is optional. It makes your Mac trust the certificates that Caddy signs on this machine, so the browser shows no warning. Caddy creates its local CA when it signs the first certificate, so do this after your first app is live.

1. Copy the CA certificate to your Mac:

   ```bash
   orb -m opencloud sudo cat /var/lib/opencloud/caddy/data/caddy/pki/authorities/local/root.crt > opencloud-local-ca.crt
   ```

2. Add it to your keychain as a trusted root. macOS asks for your password:

   ```bash
   sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain opencloud-local-ca.crt
   ```

3. Restart your browser.

To remove the trust later, open **Keychain Access**, find "Caddy Local Authority" in the **System** keychain, and delete it. If you delete the machine, delete the certificate too, because a new machine makes a new CA.

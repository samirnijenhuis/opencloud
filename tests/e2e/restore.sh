#!/usr/bin/env bash
# Restore test. Machine A installs opencloud, deploys laravel/laravel with MySQL, and backs up to an S3 server
# on the Mac. Then machine A goes away, and machine B restores the whole install from the bucket and the APP_KEY.
# Set KEEP=1 to keep machine B and the S3 container after the test.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/opencloud-restore.XXXXXX")
chmod 700 "$WORK"
JAR=$WORK/cookies.txt
REPOSITORY=${RESTORE_TEST_REPOSITORY:-laravel/laravel}
BRANCH=${RESTORE_TEST_BRANCH:-13.x}
APPLICATION=shop
EMAIL=owner@example.com
PASSWORD=restore-test-password
PROOF_EMAIL=restore-proof-$(date +%s)@example.com
S3_CONTAINER=oc-restore-s3
S3_PORT=19000
S3_HOST=${RESTORE_TEST_S3_HOST:-host.orb.internal}
S3_ACCESS_KEY=restore-test-access
S3_SECRET_KEY=restore-test-secret-$(openssl rand -hex 12)
BUCKET=opencloud-restore
# fresh-machine.sh tags its images with this version, so machine B can get the exact images of machine A.
VERSION=e2e-restore-$(date +%s)-$$
RESULTS=()
EXPECTED_CHECKS=12

record() {
    local line
    if [[ $2 == 1 ]]; then line="PASS $1"; else line="FAIL $1"; fi
    RESULTS+=("$line")
    echo "$line"
}

finish() {
    printf '%s\n' "${RESULTS[@]+"${RESULTS[@]}"}" >"$WORK/results.txt"
    [[ -n ${MACHINE_A:-} ]] && orb delete -f "$MACHINE_A" >/dev/null 2>&1
    if [[ ${KEEP:-0} == 1 ]]; then
        [[ -n ${MACHINE_B:-} ]] && echo "KEEP=1: the machine $MACHINE_B stays. Delete it with: orb delete -f $MACHINE_B"
        echo "KEEP=1: the S3 container $S3_CONTAINER stays. Delete it with: docker rm -f $S3_CONTAINER"
    else
        [[ -n ${MACHINE_B:-} ]] && orb delete -f "$MACHINE_B" >/dev/null 2>&1
        docker rm -f "$S3_CONTAINER" >/dev/null 2>&1
    fi
    rm -f "$WORK/opencloud.tar" "$WORK/caddy.tar" "$WORK/restore.env"
    echo "Results: $WORK/results.txt"
    if [[ ${#RESULTS[@]} -lt $EXPECTED_CHECKS ]]; then
        echo "FAIL the test stopped after ${#RESULTS[@]} of $EXPECTED_CHECKS checks"
        exit 1
    fi
    grep -q '^FAIL' "$WORK/results.txt" && exit 1
    exit 0
}
trap finish EXIT

kc() {
    local machine=$1
    shift
    orb -m "$machine" sudo k3s kubectl "$@"
}

# Runs PHP from stdin in the dashboard web container with Laravel booted. Stdin keeps the values off the command line.
dashboard_php() {
    {
        printf '%s\n' '<?php' 'require "/app/vendor/autoload.php";' '$app = require "/app/bootstrap/app.php";' \
            '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        cat
    } | kc "$1" -n opencloud exec -i deploy/dashboard -c web -- php
}

# Prints one value from the MySQL database of the production environment.
mysql_value() {
    local machine=$1 query=$2 pod database
    read -r pod database < <(dashboard_php "$machine" <<'PHP'
$attachment = App\Domains\Datastores\Models\DatastoreAttachment::query()->whereHas('datastore', fn ($q) => $q->where('kind', 'mysql'))->sole();
echo App\Domains\Datastores\DatastoreManifests::serverPod($attachment->datastore), ' ', $attachment->database_name, "\n";
PHP
    )
    kc "$machine" -n oc-datastores exec "$pod" -c mysql -- \
        bash -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql --user=root --batch --skip-column-names $database -e \"$query\"" 2>/dev/null
}

# Prints the status of the newest deployment of production, and its commit.
production_deployment() {
    dashboard_php "$1" <<PHP
\$deployment = App\Domains\Deployments\Models\Deployment::query()->whereHas('environment', fn (\$q) => \$q->where('name', 'production'))->latest('id')->first();
echo \$deployment?->status->value ?? 'none', ' ', \$deployment?->commit_sha ?? 'none', "\n";
PHP
}

# A first build sometimes fails on a slow npm download, so a failed deployment gets one more try with the same commit.
# Prints the status and the number of attempts.
deploy_production() {
    local machine=$1 limit=$2 status attempt
    for attempt in 1 2; do
        status=$(wait_for_production "$machine" "$limit")
        [[ $status == failed ]] || break
        dashboard_php "$machine" >&2 <<'PHP'
$log = App\Domains\Deployments\Models\Deployment::query()->latest('id')->value('log') ?? '';
echo implode("\n", array_slice(explode("\n", trim($log)), -20)), "\n";
PHP
        [[ $attempt == 2 ]] || dashboard_php "$machine" >/dev/null <<'PHP'
$production = App\Domains\Environments\Models\Environment::where('name', 'production')->sole();
app(App\Domains\Deployments\Actions\StartDeployment::class)->handle($production, null, $production->deployments()->latest('id')->value('commit_sha'));
PHP
    done
    echo "$status $attempt"
}

wait_for_production() {
    local machine=$1 limit=$2 start status
    start=$(date +%s)
    while (($(date +%s) - start < limit)); do
        read -r status _ < <(production_deployment "$machine")
        [[ $status == live || $status == failed ]] && { echo "$status"; return; }
        sleep 15
    done
    echo timeout
}

s3() {
    curl -sS --aws-sigv4 "aws:amz:us-east-1:s3" --user "$S3_ACCESS_KEY:$S3_SECRET_KEY" "$@"
}

xsrf() {
    awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))'
}

props() {
    curl -s -b "$JAR" -c "$JAR" "$BASE$1" | python3 -c '
import html, json, re, sys
match = re.search(r"data-page=\"([^\"]+)\"", sys.stdin.read())
print(json.dumps(json.loads(html.unescape(match.group(1)))["props"]) if match else "{}")'
}

send() {
    local method=$1 path=$2
    shift 2
    curl -s -o "$WORK/last-response.html" -b "$JAR" -c "$JAR" -X "$method" -w '%{http_code} %{redirect_url}' \
        -H "X-XSRF-TOKEN: $(xsrf)" -H 'Accept: text/html' "$@" "$BASE$path"
}

json() { python3 -c "import json, sys; data = json.load(sys.stdin); print($1)"; }

# 0. An S3 server on the Mac plays the backup target.
docker rm -f "$S3_CONTAINER" >/dev/null 2>&1
docker run -d --name "$S3_CONTAINER" -p "$S3_PORT:9000" -e RUSTFS_ACCESS_KEY="$S3_ACCESS_KEY" -e RUSTFS_SECRET_KEY="$S3_SECRET_KEY" \
    -e RUSTFS_VOLUMES=/data rustfs/rustfs:1.0.3 >/dev/null || exit 1
for _ in $(seq 1 30); do
    s3 -f -o /dev/null -X PUT "http://127.0.0.1:$S3_PORT/$BUCKET" 2>/dev/null && break
    sleep 1
done
s3 -f -o /dev/null -I "http://127.0.0.1:$S3_PORT/$BUCKET" || { echo "The S3 server on the Mac did not create the bucket $BUCKET."; exit 1; }

# 1. Machine A: a normal install with an application that deploys.
KEEP=1 E2E_VERSION="$VERSION" bash "$ROOT/tests/e2e/fresh-machine.sh" >"$WORK/install-a.log" 2>&1
MACHINE_A=$(sed -n 's/^KEEP=1: the machine \([^ ]*\) stays.*/\1/p' "$WORK/install-a.log")
setup_url=$(sed -n 's/^PASS the installer prints one Setup URL line (Setup URL: \(.*\))$/\1/p' "$WORK/install-a.log")
install_seconds=$(sed -n 's/^Install time: \([0-9]*\)s$/\1/p' "$WORK/install-a.log" | tail -1)
ok=0
# The e2e tag is shared on the Mac, so a build from another checkout can replace it between the build and the save.
[[ -n $MACHINE_A && -n $setup_url ]] && ! grep -q '^FAIL' "$WORK/install-a.log" &&
    kc "$MACHINE_A" -n opencloud exec deploy/dashboard -c web -- php artisan list --raw | grep -q '^opencloud:fetch-dashboard-backup ' && ok=1
record "machine A installs this checkout and passes every fresh machine check (${install_seconds:-?}s)" "$ok"
[[ $ok == 1 ]] || { tail -40 "$WORK/install-a.log"; exit 1; }

BASE=${setup_url%%/setup/*}
kc "$MACHINE_A" -n opencloud set env deploy/dashboard OPENCLOUD_TLS_ISSUER=internal >/dev/null
kc "$MACHINE_A" -n opencloud rollout status deploy/dashboard --timeout=300s >/dev/null
for _ in $(seq 1 60); do
    [[ $(curl -s -o /dev/null -m 5 -w '%{http_code}' "$BASE/up") == 200 ]] && break
    sleep 2
done

curl -s -b "$JAR" -c "$JAR" -o /dev/null "$setup_url"
send POST "${setup_url#"$BASE"}" --data-urlencode name=Owner --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD" >/dev/null

dashboard_php "$MACHINE_A" >"$WORK/create.log" 2>&1 <<PHP
\$owner = App\Domains\Members\Models\User::where('email', '$EMAIL')->sole();
app(App\Domains\Backups\Actions\StoreBackupTarget::class)->handle(\$owner, 'http://$S3_HOST:$S3_PORT', '$BUCKET', '$S3_ACCESS_KEY', '$S3_SECRET_KEY');
\$application = app(App\Domains\Applications\Actions\CreateApplication::class)->execute(\$owner, [
    'name' => '$APPLICATION',
    'repository' => '$REPOSITORY',
    'branch' => '$BRANCH',
    'cluster_id' => App\Domains\Clusters\Models\Cluster::where('kind', 'local')->value('id'),
]);
\$production = \$application->environments()->where('name', 'production')->sole();
app(App\Domains\Deployments\Actions\StartDeployment::class)->execute(\$production, \$owner);
echo 'created', "\n";
PHP
grep -qx created "$WORK/create.log" || { echo "The owner, the backup target, or the application was not created on machine A."; cat "$WORK/create.log"; exit 1; }
start=$(date +%s)
# A first build downloads every Composer and npm package, and the shared test network is sometimes slow.
read -r status attempts < <(deploy_production "$MACHINE_A" 3600)
read -r _ LIVE_SHA < <(production_deployment "$MACHINE_A")
ok=0
[[ $status == live ]] && ok=1
record "machine A deploys $REPOSITORY $BRANCH with MySQL (status $status after $(($(date +%s) - start))s, $attempts attempts)" "$ok"
[[ $ok == 1 ]] || { cat "$WORK/create.log"; exit 1; }

mysql_value "$MACHINE_A" "CREATE TABLE restore_proof (email VARCHAR(255) PRIMARY KEY); INSERT INTO restore_proof VALUES ('$PROOF_EMAIL')" >/dev/null
row=$(mysql_value "$MACHINE_A" "SELECT email FROM restore_proof")
ok=0
[[ $row == "$PROOF_EMAIL" ]] && ok=1
record "machine A has the row $PROOF_EMAIL in the MySQL database of production" "$ok"

# 2. Back up to the bucket, then save the APP_KEY and lose machine A. The backup target exists since step 1.
dashboard_php "$MACHINE_A" >"$WORK/backup.log" 2>&1 <<PHP
\$owner = App\Domains\Members\Models\User::where('email', '$EMAIL')->sole();
\$attachment = App\Domains\Datastores\Models\DatastoreAttachment::query()->whereHas('datastore', fn (\$q) => \$q->where('kind', 'mysql'))->sole();
app(App\Domains\Backups\Actions\BackupNow::class)->handle(\$attachment, \$owner);
for (\$i = 0; \$i < 120 && \$attachment->backups()->where('status', 'done')->doesntExist(); \$i++) {
    sleep(2);
}
App\Domains\Backups\Jobs\BackupDashboard::dispatchSync();
PHP
listing=$(s3 "http://127.0.0.1:$S3_PORT/$BUCKET?list-type=2&prefix=backups/")
ok=0
grep -Eq '<Key>backups/[0-9]+/[0-9]+/[0-9-]+\.sql\.gz</Key>' <<<"$listing" && grep -Eq '<Key>backups/dashboard/[0-9-]+\.sqlite\.gz</Key>' <<<"$listing" && ok=1
record "BackupNow and BackupDashboard put a database dump and a dashboard copy in the bucket" "$ok"
[[ $ok == 1 ]] || { cat "$WORK/backup.log"; exit 1; }

APP_KEY=$(orb -m "$MACHINE_A" sudo cat /var/lib/opencloud/APP_KEY)
(
    umask 077
    printf '%s\n' "# The restore file of the restore test" "BACKUP_ENDPOINT=http://$S3_HOST:$S3_PORT" "BACKUP_REGION=us-east-1" \
        "BACKUP_BUCKET=$BUCKET" "BACKUP_ACCESS_KEY=$S3_ACCESS_KEY" "BACKUP_SECRET_KEY=$S3_SECRET_KEY" "BACKUP_PATH_STYLE=true" \
        "APP_KEY=$APP_KEY" >"$WORK/restore.env"
)
# Machine B runs the exact images of machine A. The e2e tag on the Mac can change when another build runs.
for image in opencloud opencloud-caddy; do
    orb -m "$MACHINE_A" sudo k3s ctr images export "/tmp/$image.tar" "ghcr.io/opencloud-dev/$image:$VERSION" >/dev/null
    orb -m "$MACHINE_A" sudo cat "/tmp/$image.tar" >"$WORK/${image#opencloud-}.tar"
done
[[ -s $WORK/opencloud.tar && -s $WORK/caddy.tar ]] || { echo "The images of machine A were not exported."; exit 1; }
orb delete -f "$MACHINE_A" >/dev/null
MACHINE_A=

# 3. Machine B: a new server restores the install from the bucket and the APP_KEY.
MACHINE_B=oc-restore-$(date +%s)
orb create "ubuntu:noble" "$MACHINE_B" >/dev/null || exit 1
IP=$(orb -m "$MACHINE_B" hostname -I | awk '{ print $1 }')
BASE=http://$IP:8000
orb -m "$MACHINE_B" sudo mkdir -p /opt/opencloud
orb -m "$MACHINE_B" sudo cp -R "/mnt/mac$ROOT/install.sh" "/mnt/mac$ROOT/platform" "/mnt/mac$ROOT/bin" "/mnt/mac$WORK/opencloud.tar" "/mnt/mac$WORK/caddy.tar" /opt/opencloud/
orb -m "$MACHINE_B" sudo install -m 600 "/mnt/mac$WORK/restore.env" /root/opencloud-restore.env
rm -f "$WORK/restore.env"

start=$(date +%s)
install_status=0
orb -m "$MACHINE_B" sudo env OPENCLOUD_VERSION="$VERSION" OPENCLOUD_IMAGE_ARCHIVE=/opt/opencloud/opencloud.tar \
    OPENCLOUD_CADDY_IMAGE_ARCHIVE=/opt/opencloud/caddy.tar OPENCLOUD_TLS_ISSUER=internal \
    OPENCLOUD_RESTORE_FILE=/root/opencloud-restore.env bash /opt/opencloud/install.sh >"$WORK/install-b.log" 2>&1 || install_status=$?
ok=0
[[ $install_status == 0 ]] && grep -q '^Restored the dashboard database from backups/dashboard/' "$WORK/install-b.log" &&
    grep -q '^The restore goes on in the background' "$WORK/install-b.log" && ok=1
record "the installer in restore mode on machine B exits 0 and restores the dashboard database ($(($(date +%s) - start))s)" "$ok"
[[ $ok == 1 ]] || { tail -40 "$WORK/install-b.log"; exit 1; }

ok=0
! grep -qF "$S3_SECRET_KEY" "$WORK/install-b.log" && ! grep -qF "${APP_KEY#base64:}" "$WORK/install-b.log" &&
    ! kc "$MACHINE_B" -n opencloud get secrets -o yaml | grep -qF "$(printf '%s' "$S3_SECRET_KEY" | base64)" && ok=1
record "the install log of machine B holds neither the bucket secret nor the APP_KEY, and no Secret in opencloud holds the bucket secret" "$ok"

# 4. The owner logs in with the old password and sees the application.
rm -f "$JAR"
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/login"
answer=$(send POST /login --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD")
ok=0
[[ $answer == "302 $BASE/dashboard" ]] && ok=1
record "the owner logs in on machine B with the old password ($answer)" "$ok"

application_id=$(props /applications | json "[a['id'] for a in data['applications'] if a['name'] == '$APPLICATION'][0]" 2>/dev/null)
environments=$(props "/applications/$application_id" | json "','.join(e['name'] for e in data['environments'])" 2>/dev/null)
ok=0
[[ -n $application_id && $environments == production ]] && ok=1
record "machine B lists the application $APPLICATION with its environment ($environments)" "$ok"

# 5. The restore steps finish, the data is back, and the old commit goes live again.
start=$(date +%s)
while (($(date +%s) - start < 1800)); do
    orb -m "$MACHINE_B" sudo opencloud restore-status >"$WORK/restore-status.txt" 2>&1
    grep -qE '^(The restore is done|A step failed)' "$WORK/restore-status.txt" && break
    sleep 10
done
cat "$WORK/restore-status.txt"
ok=0
[[ $(grep -c '^done ' "$WORK/restore-status.txt") == 7 ]] && grep -q '^The restore is done.$' "$WORK/restore-status.txt" && ok=1
record "every restore step on machine B is done ($(($(date +%s) - start))s)" "$ok"

row=$(mysql_value "$MACHINE_B" "SELECT email FROM restore_proof")
ok=0
[[ $row == "$PROOF_EMAIL" ]] && ok=1
record "the MySQL database on machine B has the row $PROOF_EMAIL from machine A" "$ok"

start=$(date +%s)
read -r status attempts < <(deploy_production "$MACHINE_B" 3600)
read -r _ sha < <(production_deployment "$MACHINE_B")
ok=0
[[ $status == live && $sha == "$LIVE_SHA" ]] && ok=1
record "machine B deploys the old commit ${LIVE_SHA:0:12} and it goes live (status $status after $(($(date +%s) - start))s, $attempts attempts)" "$ok"

HOSTNAME_UNDER_TEST=$APPLICATION.$IP.sslip.io
dashboard_php "$MACHINE_B" >"$WORK/domain.log" 2>&1 <<PHP
\$production = App\Domains\Environments\Models\Environment::where('name', 'production')->sole();
app(App\Domains\Routing\Actions\AddDomain::class)->handle(\$production, App\Domains\Members\Models\User::where('email', '$EMAIL')->sole(), '$HOSTNAME_UNDER_TEST');
PHP
code=000
for _ in $(seq 1 60); do
    code=$(curl -sk -o /dev/null -m 5 -w '%{http_code}' "https://$HOSTNAME_UNDER_TEST/")
    [[ $code == 200 ]] && break
    sleep 5
done
ok=0
[[ $code == 200 ]] && ok=1
record "the restored app answers 200 over HTTPS on $HOSTNAME_UNDER_TEST (got $code)" "$ok"

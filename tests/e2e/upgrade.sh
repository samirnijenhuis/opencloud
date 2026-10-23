#!/usr/bin/env bash
# Release test for `opencloud upgrade`. Installs release A on a new OrbStack machine with curl | bash and deploys an
# application. Then it upgrades to release B while it requests the application each second. Last, it upgrades to
# release C, whose dashboard fails on purpose, and checks that the upgrade goes back to B and its database.
# A and B are the current tree. Set KEEP=1 to keep the machine after the test.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
MACHINE=oc-upgrade-$(date +%s)
ARCH=$(docker version --format '{{.Server.Arch}}')
A=v0.0.1-e2e
B=v0.0.2-e2e
C=v0.0.3-e2e
IMAGES=ghcr.io/opencloud-dev
WORK=$(mktemp -d "${TMPDIR:-/tmp}/oc-upgrade.XXXXXX")
WORK=$(cd "$WORK" && pwd -P)
SERVE=$WORK/serve
# OrbStack machines see the Mac file system under /mnt/mac.
REMOTE_WORK=/mnt/mac$WORK
REPOSITORY=${UPGRADE_TEST_REPOSITORY:-laravel/laravel}
BRANCH=${UPGRADE_TEST_BRANCH:-13.x}
EMAIL=owner@example.com
PASSWORD=upgrade-test-password
JAR=$WORK/cookies.txt
RESULTS=()
EXPECTED_CHECKS=14
SERVER_PID=
POLLER_PID=

record() {
    local line
    if [[ $2 == 1 ]]; then line="PASS $1"; else line="FAIL $1"; fi
    RESULTS+=("$line")
    echo "$line"
}

finish() {
    [[ -z $POLLER_PID ]] || kill "$POLLER_PID" 2>/dev/null
    [[ -z $SERVER_PID ]] || kill "$SERVER_PID" 2>/dev/null
    rm -f "$WORK"/*.tar
    printf '%s\n' "${RESULTS[@]+"${RESULTS[@]}"}" >"$WORK/results.txt"
    if [[ ${KEEP:-0} == 1 ]]; then
        echo "KEEP=1: the machine $MACHINE stays. Delete it with: orb delete -f $MACHINE"
    else
        orb delete -f "$MACHINE" >/dev/null 2>&1
    fi
    echo "Logs and results: $WORK"
    if [[ ${#RESULTS[@]} -lt $EXPECTED_CHECKS ]]; then
        echo "FAIL the test stopped after ${#RESULTS[@]} of $EXPECTED_CHECKS checks"
        exit 1
    fi
    grep -q '^FAIL' "$WORK/results.txt" && exit 1
    exit 0
}
trap finish EXIT

on_machine() { orb -m "$MACHINE" sudo "$@"; }

kc() { on_machine k3s kubectl "$@"; }

xsrf() {
    awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))'
}

# Prints the Inertia page props of a dashboard page as JSON.
props() {
    curl -s -b "$JAR" -c "$JAR" "$BASE$1" | python3 -c '
import html, json, re, sys
match = re.search(r"data-page=\"([^\"]+)\"", sys.stdin.read())
print(json.dumps(json.loads(html.unescape(match.group(1)))["props"]) if match else "{}")'
}

# Sends a form like the browser does and prints "<status> <location>".
send() {
    local method=$1 path=$2
    shift 2
    curl -s -o "$WORK/last-response.html" -b "$JAR" -c "$JAR" -X "$method" -w '%{http_code} %{redirect_url}' \
        -H "X-XSRF-TOKEN: $(xsrf)" -H 'Accept: text/html' "$@" "$BASE$path"
}

json() { python3 -c "import json, sys; data = json.load(sys.stdin); print($1)"; }

# Logs in with the cookie jar in $JAR and prints "<status> <location>". Give each login a new jar.
login() {
    curl -s -o /dev/null -b "$JAR" -c "$JAR" "$BASE/login"
    send POST /login --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD"
}

wait_for_deployment() {
    local id=$1 limit=$2 start status
    start=$(date +%s)
    while (($(date +%s) - start < limit)); do
        status=$(props "/deployments/$id" | json 'data["deployment"]["status"]')
        [[ $status == live || $status == failed ]] && { echo "$status"; return; }
        sleep 10
    done
    echo timeout
}

live_deployment() {
    props "/environments/$ENVIRONMENT/deployments" | json '[d["id"] for d in data["deployments"] if d["status"] == "live"][0]'
}

# The address is fixed, so a slow DNS answer for sslip.io cannot count as a failed request.
site_code() { curl -sk -o /dev/null -m 5 -w '%{http_code}' --resolve "$HOSTNAME_UNDER_TEST:443:$IP" "https://$HOSTNAME_UNDER_TEST/"; }

# Usage: poll <file>. Requests the site each second and writes "<time> <status>" lines until it is stopped.
poll() {
    while :; do
        echo "$(date +%s) $(site_code)" >>"$1"
        sleep 1
    done
}

start_polling() {
    poll "$1" &
    POLLER_PID=$!
}

stop_polling() {
    kill "$POLLER_PID" 2>/dev/null
    wait "$POLLER_PID" 2>/dev/null
    POLLER_PID=
}

# Usage: failed_requests <file>. Prints "<failed> of <total>".
failed_requests() {
    echo "$(awk '$2 != 200' "$1" | wc -l | tr -d ' ') of $(wc -l <"$1" | tr -d ' ')"
}

dashboard_image() { kc -n opencloud get deployment dashboard -o 'jsonpath={.spec.template.spec.containers[?(@.name=="web")].image}'; }

caddy_image() { kc -n opencloud get deployment caddy -o 'jsonpath={.spec.template.spec.containers[0].image}'; }

# Usage: upgrade <version> <log>. Runs opencloud upgrade on the machine with the images of that release.
upgrade() {
    on_machine env OPENCLOUD_RELEASE_URL="$BASE_RELEASES" OPENCLOUD_IMAGE_ARCHIVE="/opt/oc-test/dashboard-$1.tar" \
        OPENCLOUD_CADDY_IMAGE_ARCHIVE="/opt/oc-test/caddy-$1.tar" opencloud upgrade "$1" >"$2" 2>&1
}

echo "Building the images of $A, $B, and $C for linux/$ARCH"
docker build --platform "linux/$ARCH" -t "$IMAGES/opencloud:$A" "$ROOT/dashboard" >"$WORK/build.log" 2>&1 ||
    { tail -50 "$WORK/build.log"; exit 1; }
docker build --platform "linux/$ARCH" -t "$IMAGES/opencloud-caddy:$A" "$ROOT/platform/images/caddy" >"$WORK/build-caddy.log" 2>&1 ||
    { tail -50 "$WORK/build-caddy.log"; exit 1; }
docker tag "$IMAGES/opencloud:$A" "$IMAGES/opencloud:$B"
docker tag "$IMAGES/opencloud-caddy:$A" "$IMAGES/opencloud-caddy:$B"
docker tag "$IMAGES/opencloud-caddy:$A" "$IMAGES/opencloud-caddy:$C"

# The dashboard of release C changes the database like a migration does and leaves a file as proof, then it fails.
mkdir -p "$WORK/broken"
cat >"$WORK/broken/entrypoint.sh" <<'EOF'
#!/bin/sh
php -r '$db = new PDO("sqlite:" . getenv("DB_DATABASE")); $db->exec("CREATE TABLE IF NOT EXISTS upgrade_test_marker (id INTEGER)");'
touch /var/lib/opencloud/data/release-c-ran
echo "This dashboard fails on purpose." >&2
exit 1
EOF
cat >"$WORK/broken/Dockerfile" <<EOF
FROM $IMAGES/opencloud:$B
COPY --chmod=0755 entrypoint.sh /usr/local/bin/opencloud-entrypoint
EOF
docker build --platform "linux/$ARCH" -t "$IMAGES/opencloud:$C" "$WORK/broken" >"$WORK/build-broken.log" 2>&1 ||
    { tail -50 "$WORK/build-broken.log"; exit 1; }

for version in "$A" "$B" "$C"; do
    docker save --platform "linux/$ARCH" -o "$WORK/dashboard-$version.tar" "$IMAGES/opencloud:$version"
    docker save --platform "linux/$ARCH" -o "$WORK/caddy-$version.tar" "$IMAGES/opencloud-caddy:$version"
    "$ROOT/tests/e2e/build-release-archive.sh" "$version" "$SERVE" >/dev/null
done
cp "$ROOT/install.sh" "$SERVE/install.sh"

PORT=$(python3 -c 'import socket; s = socket.socket(); s.bind(("", 0)); print(s.getsockname()[1])')
python3 -m http.server "$PORT" --directory "$SERVE" >"$WORK/http.log" 2>&1 &
SERVER_PID=$!
BASE_RELEASES=http://host.orb.internal:$PORT

echo "Creating the machine $MACHINE"
orb create "ubuntu:noble" "$MACHINE" >/dev/null || exit 1
IP=$(orb -m "$MACHINE" hostname -I | awk '{ print $1 }')
BASE=http://$IP:8000
HOSTNAME_UNDER_TEST=upgrade.$IP.sslip.io
on_machine mkdir -p /opt/oc-test
on_machine cp "$REMOTE_WORK"/dashboard-{"$A","$B","$C"}.tar "$REMOTE_WORK"/caddy-{"$A","$B","$C"}.tar /opt/oc-test/
rm -f "$WORK"/*.tar

# 1. Install release A.
start=$(date +%s)
on_machine bash -c "curl -fsSL $BASE_RELEASES/install.sh | OPENCLOUD_RELEASE_URL=$BASE_RELEASES OPENCLOUD_VERSION=$A OPENCLOUD_TLS_ISSUER=internal OPENCLOUD_IMAGE_ARCHIVE=/opt/oc-test/dashboard-$A.tar OPENCLOUD_CADDY_IMAGE_ARCHIVE=/opt/oc-test/caddy-$A.tar bash" \
    >"$WORK/install.log" 2>&1
status=$?
setup_url=$(sed -n 's/^Setup URL: //p' "$WORK/install.log")
ok=0
[[ $status == 0 && $setup_url == "$BASE/setup/"* ]] && ok=1
record "release $A installs with curl | bash ($(($(date +%s) - start))s)" "$ok"
[[ $ok == 1 ]] || { tail -40 "$WORK/install.log"; exit 1; }

# 2. Create the owner and an application, and wait until the application is live.
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$setup_url"
answer=$(send POST "${setup_url#"$BASE"}" --data-urlencode name=Owner --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD")
ok=0
[[ $answer == "302 $BASE/dashboard" ]] && ok=1
record "the setup page creates the owner ($answer)" "$ok"
[[ $ok == 1 ]] || exit 1

cluster_id=$(props /applications/create | json 'data["clusters"][0]["id"]')
answer=$(send POST /applications \
    --data-urlencode "repository=$REPOSITORY" --data-urlencode "branch=$BRANCH" --data-urlencode "cluster_id=$cluster_id" \
    --data-urlencode type=laravel --data-urlencode size=s --data-urlencode min_instances=1 --data-urlencode max_instances=1 \
    --data-urlencode cpu_target=70 --data-urlencode 'deploy_commands[]=php artisan migrate --force' \
    --data-urlencode worker_enabled=0 --data-urlencode scheduler_enabled=0 \
    --data-urlencode "domain=$HOSTNAME_UNDER_TEST" --data-urlencode database=mysql)
ENVIRONMENT=$(sed -E 's#.*/environments/([0-9]+).*#\1#' <<<"$answer")
ok=0
[[ $answer =~ ^302\ .*/environments/[0-9]+$ ]] && ok=1
record "the new application form creates the application ($answer)" "$ok"
[[ $ok == 1 ]] || exit 1

first_id=$(props "/environments/$ENVIRONMENT/deployments" | json 'data["deployments"][0]["id"]')
start=$(date +%s)
# A first build downloads every Composer and npm package.
status=$(wait_for_deployment "$first_id" 3600)
ok=0
[[ $status == live ]] && ok=1
record "the first deployment goes live (status $status after $(($(date +%s) - start))s)" "$ok"
[[ $ok == 1 ]] || exit 1

for _ in $(seq 1 60); do [[ $(site_code) == 200 ]] && break; sleep 5; done
code=$(site_code)
ok=0
[[ $code == 200 ]] && ok=1
record "the application answers 200 on https://$HOSTNAME_UNDER_TEST/ (got $code)" "$ok"
[[ $ok == 1 ]] || exit 1
live_before=$(live_deployment)

# 3. Upgrade to B while the application gets one request each second.
start_polling "$WORK/poll-$B.txt"
start=$(date +%s)
upgrade "$B" "$WORK/upgrade-$B.log"
status=$?
seconds=$(($(date +%s) - start))
sleep 5
stop_polling
cat "$WORK/upgrade-$B.log"
ok=0
[[ $status == 0 ]] && grep -q "^Opencloud $B runs. " "$WORK/upgrade-$B.log" && ok=1
record "opencloud upgrade $B exits 0 (${seconds}s)" "$ok"
[[ $ok == 1 ]] || exit 1

JAR=$WORK/cookies-$B.txt
answer=$(login)
ok=0
[[ $answer == "302 $BASE/dashboard" ]] && ok=1
record "the owner logs in after the upgrade ($answer)" "$ok"

version=$(props /applications | json 'data["opencloud"]["version"]')
issuer=$(kc -n opencloud get deployment dashboard -o 'jsonpath={.spec.template.spec.containers[?(@.name=="web")].env[?(@.name=="OPENCLOUD_TLS_ISSUER")].value}')
ok=0
[[ $version == "$B" && $(dashboard_image) == "$IMAGES/opencloud:$B" && $(caddy_image) == "$IMAGES/opencloud-caddy:$B" && $issuer == internal ]] && ok=1
record "the dashboard reports $B, runs the images of $B, and keeps the TLS issuer (version $version, issuer $issuer)" "$ok"

failed=$(failed_requests "$WORK/poll-$B.txt")
ok=0
[[ $failed == "0 of "* && $failed != "0 of 0" ]] && ok=1
record "the application answers each request during the upgrade ($failed failed)" "$ok"

applications=$(props /applications | json 'len(data["applications"])')
live_after=$(live_deployment)
copies=$(on_machine sh -c "ls /var/lib/opencloud/data/upgrade-$A-to-$B-*.sqlite" 2>/dev/null)
ok=0
[[ $applications == 1 && $live_after == "$live_before" && -n $copies ]] && ok=1
record "the data is still there (applications: $applications, live deployment: $live_before then $live_after, copy: $copies)" "$ok"

# 4. Upgrade to C, whose dashboard fails. The command must go back to B and its database.
start_polling "$WORK/poll-$C.txt"
start=$(date +%s)
upgrade "$C" "$WORK/upgrade-$C.log"
status=$?
seconds=$(($(date +%s) - start))
sleep 5
stop_polling
cat "$WORK/upgrade-$C.log"
ok=0
[[ $status != 0 ]] && grep -q "^Opencloud $B runs again with the database from before the upgrade" "$WORK/upgrade-$C.log" && ok=1
record "opencloud upgrade $C fails and goes back to $B (exit $status, ${seconds}s)" "$ok"

JAR=$WORK/cookies-$C.txt
answer=$(login)
version=$(props /applications | json 'data["opencloud"]["version"]')
ok=0
[[ $answer == "302 $BASE/dashboard" && $version == "$B" && $(dashboard_image) == "$IMAGES/opencloud:$B" && $(caddy_image) == "$IMAGES/opencloud-caddy:$B" ]] && ok=1
record "the owner logs in, and the dashboard reports $B with the images of $B ($answer, version $version)" "$ok"

ran=0
on_machine test -f /var/lib/opencloud/data/release-c-ran && ran=1
tables=$(kc -n opencloud exec deploy/dashboard -c web -- php -r \
    'echo (new PDO("sqlite:" . getenv("DB_DATABASE")))->query("select count(*) from sqlite_master where name = '\''upgrade_test_marker'\''")->fetchColumn();')
ok=0
[[ $ran == 1 && $tables == 0 ]] && ok=1
record "release C changed the database, and the copy from before the upgrade replaced it (C ran: $ran, marker tables: $tables)" "$ok"

failed=$(failed_requests "$WORK/poll-$C.txt")
applications=$(props /applications | json 'len(data["applications"])')
code=$(curl -s -o /dev/null -m 5 -w '%{http_code}' "$BASE/up")
ok=0
[[ $failed == "0 of "* && $failed != "0 of 0" && $applications == 1 && $code == 200 ]] && ok=1
record "the application answers each request during the failed upgrade, and the dashboard answers /up ($failed failed, /up $code)" "$ok"

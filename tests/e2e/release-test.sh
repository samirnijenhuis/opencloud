#!/usr/bin/env bash
# The release test from the design document. It installs opencloud on a new OrbStack machine
# and drives the dashboard over HTTP, the way a person does in the browser.
# Set KEEP=1 to keep the machine after the test.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/opencloud-release.XXXXXX")
JAR=$WORK/cookies.txt
REPOSITORY=${RELEASE_TEST_REPOSITORY:-laravel/laravel}
BRANCH=${RELEASE_TEST_BRANCH:-13.x}
RESULTS=()
EXPECTED_CHECKS=8

record() {
    local line
    if [[ $2 == 1 ]]; then line="PASS $1"; else line="FAIL $1"; fi
    RESULTS+=("$line")
    echo "$line"
}

finish() {
    printf '%s\n' "${RESULTS[@]+"${RESULTS[@]}"}" >"$WORK/results.txt"
    if [[ ${KEEP:-0} == 1 || -z ${MACHINE:-} ]]; then
        [[ -n ${MACHINE:-} ]] && echo "KEEP=1: the machine $MACHINE stays. Delete it with: orb delete -f $MACHINE"
    else
        orb delete -f "$MACHINE" >/dev/null 2>&1
    fi
    echo "Results: $WORK/results.txt"
    if [[ ${#RESULTS[@]} -lt $EXPECTED_CHECKS ]]; then
        echo "FAIL the test stopped after ${#RESULTS[@]} of $EXPECTED_CHECKS checks"
        exit 1
    fi
    grep -q '^FAIL' "$WORK/results.txt" && exit 1
    exit 0
}
trap finish EXIT

kc() { orb -m "$MACHINE" sudo k3s kubectl "$@"; }

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

latest_deployment() {
    props "/environments/$ENVIRONMENT/deployments" | json 'data["deployments"][0]["id"], data["deployments"][0]["status"]' | tr -d '(),'"'"
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

site_code() { curl -sk -o /dev/null -m 5 -w '%{http_code}' "https://$HOSTNAME_UNDER_TEST/"; }

# 1. Install on a new machine.
install_log=$WORK/install.log
KEEP=1 bash "$ROOT/tests/e2e/fresh-machine.sh" >"$install_log" 2>&1
MACHINE=$(sed -n 's/^KEEP=1: the machine \([^ ]*\) stays.*/\1/p' "$install_log")
setup_url=$(sed -n 's/^PASS the installer prints one Setup URL line (Setup URL: \(.*\))$/\1/p' "$install_log")
install_seconds=$(sed -n 's/^Install time: \([0-9]*\)s$/\1/p' "$install_log" | tail -1)
ok=0
[[ -n $MACHINE && -n $setup_url ]] && ! grep -q '^FAIL' "$install_log" && ok=1
record "the install on a new machine passes every fresh machine check (${install_seconds:-?}s)" "$ok"
[[ $ok == 1 ]] || { tail -40 "$install_log"; exit 1; }

BASE=${setup_url%%/setup/*}
IP=$(sed -E 's#http://([^:]+):.*#\1#' <<<"$BASE")
HOSTNAME_UNDER_TEST=release.$IP.sslip.io

# Let's Encrypt cannot reach a private test machine, so the test uses the Caddy local CA.
kc -n opencloud set env deploy/dashboard OPENCLOUD_TLS_ISSUER=internal >/dev/null
kc -n opencloud rollout status deploy/dashboard --timeout=300s >/dev/null
for _ in $(seq 1 60); do
    [[ $(curl -s -o /dev/null -m 5 -w '%{http_code}' "$BASE/up") == 200 ]] && break
    sleep 2
done

# 2. Create the owner through the setup page.
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$setup_url"
answer=$(send POST "${setup_url#"$BASE"}" --data-urlencode name=Release --data-urlencode email=owner@example.com \
    --data-urlencode password=release-test-password --data-urlencode password_confirmation=release-test-password)
ok=0
[[ $answer == "302 $BASE/dashboard" ]] && ok=1
record "the setup page creates the owner and logs in ($answer)" "$ok"
[[ $ok == 1 ]] || exit 1

# 3. Create an application from a public repository and wait for the first deployment.
cluster_id=$(props /applications/create | json 'data["clusters"][0]["id"]')
answer=$(send POST /applications \
    --data-urlencode "repository=$REPOSITORY" --data-urlencode "branch=$BRANCH" --data-urlencode "cluster_id=$cluster_id" \
    --data-urlencode type=laravel --data-urlencode size=s --data-urlencode min_instances=1 --data-urlencode max_instances=2 \
    --data-urlencode cpu_target=70 --data-urlencode 'deploy_commands[]=php artisan migrate --force' \
    --data-urlencode worker_enabled=1 --data-urlencode scheduler_enabled=1 \
    --data-urlencode "domain=$HOSTNAME_UNDER_TEST" --data-urlencode database=mysql \
    --data-urlencode 'env[0][key]=RELEASE_SECRET' --data-urlencode 'env[0][value]=release-secret-value-4711' --data-urlencode 'env[0][is_secret]=1')
ENVIRONMENT=$(sed -E 's#.*/environments/([0-9]+).*#\1#' <<<"$answer")
ok=0
[[ $answer =~ ^302\ .*/environments/[0-9]+$ ]] && ok=1
record "the new application form creates the application ($answer)" "$ok"
[[ $ok == 1 ]] || exit 1

read -r first_id _ < <(latest_deployment)
first_start=$(date +%s)
# A first build downloads every Composer and npm package, and the shared test network is sometimes slow.
status=$(wait_for_deployment "$first_id" 3600)
ok=0
[[ $status == live ]] && ok=1
record "the first deployment goes live (status $status after $(($(date +%s) - first_start))s)" "$ok"

# 4. The domain answers on HTTPS, and HTTP redirects to HTTPS.
for _ in $(seq 1 30); do [[ $(site_code) == 200 ]] && break; sleep 5; done
code=$(site_code)
redirect=$(curl -s -o /dev/null -m 5 -w '%{http_code}' "http://$HOSTNAME_UNDER_TEST/")
ok=0
[[ $code == 200 && $redirect == 308 ]] && ok=1
record "the domain answers 200 on HTTPS and 308 on HTTP (got $code and $redirect)" "$ok"

# The secret never reaches the browser, and the app gets it at runtime.
env_page=$(curl -s -b "$JAR" "$BASE/environments/$ENVIRONMENT/settings")
in_pod=$(kc -n "oc-env-$ENVIRONMENT" exec deploy/web -- printenv RELEASE_SECRET 2>/dev/null)
ok=0
[[ $env_page != *release-secret-value-4711* && $in_pod == release-secret-value-4711 ]] && ok=1
record "the secret is in the pod but not in the dashboard page" "$ok"

# 6. A new deployment goes live with no failed request.
send POST "/environments/$ENVIRONMENT/deployments" >/dev/null
read -r second_id _ < <(latest_deployment)
failed=0 total=0
while :; do
    status=$(props "/deployments/$second_id" | json 'data["deployment"]["status"]')
    [[ $status == live || $status == failed ]] && break
    for _ in 1 2 3 4; do
        total=$((total + 1))
        [[ $(site_code) == 200 ]] || failed=$((failed + 1))
        sleep 0.5
    done
done
ok=0
[[ $status == live && $failed == 0 && $total -gt 0 ]] && ok=1
record "a redeploy goes live with no failed request ($failed of $total failed)" "$ok"

# 7. A failing deploy command leaves the old version live.
settings=$(props "/environments/$ENVIRONMENT/settings" | json 'json.dumps(data["settings"])')
python3 - "$settings" >"$WORK/settings.args" <<'PY'
import json, sys
settings = json.loads(sys.argv[1])
settings["deploy_commands"] = ["php artisan migrate --force", "echo broken on purpose && exit 3"]
for key, value in settings.items():
    if isinstance(value, list):
        for item in value:
            print(f"{key}[]={item}")
    elif isinstance(value, bool):
        print(f"{key}={int(value)}")
    elif value is not None:
        print(f"{key}={value}")
PY
args=()
while IFS= read -r line; do args+=(--data-urlencode "$line"); done <"$WORK/settings.args"
send PATCH "/environments/$ENVIRONMENT/settings" "${args[@]}" >/dev/null
send POST "/environments/$ENVIRONMENT/deployments" >/dev/null
read -r third_id _ < <(latest_deployment)
status=$(wait_for_deployment "$third_id" 1200)
code=$(site_code)
live_id=$(props "/environments/$ENVIRONMENT/deployments" | json '[d["id"] for d in data["deployments"] if d["status"] == "live"][0]')
ok=0
[[ $status == failed && $code == 200 && $live_id == "$second_id" ]] && ok=1
record "a failing deploy command keeps the old version live (new: $status, site: $code, live: $live_id)" "$ok"

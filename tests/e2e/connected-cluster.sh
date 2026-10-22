#!/usr/bin/env bash
# Connects a plain k3s machine to an opencloud machine through the dashboard routes, deploys laravel/laravel on it
# with MySQL, and proves that the connected cluster starts a new web pod while the opencloud machine is stopped.
# Usage: connected-cluster.sh <opencloud machine> <setup URL> <k3s machine>
# Make the k3s machine with plain-k3s.sh. Make the opencloud machine with KEEP=1 fresh-machine.sh.
# With ENVIRONMENT_ID set, the script logs in, deploys that environment again, and goes on from there.
set -euo pipefail

A=${1:?Usage: connected-cluster.sh <opencloud machine> <setup URL> <k3s machine>}
SETUP_URL=${2:?}
B=${3:?}
REGISTRY_USER=${REGISTRY_USER:-opencloud}
REGISTRY_PASSWORD=${REGISTRY_PASSWORD:-registry-test-password}
NAME=${APP_NAME:-shop}

A_IP=$(orb -m "$A" hostname -I | awk '{ print $1 }')
B_IP=$(orb -m "$B" hostname -I | awk '{ print $1 }')
DASHBOARD=http://$A_IP:8000
HOST=$NAME.$B_IP.sslip.io
WORK=$(mktemp -d "${TMPDIR:-/tmp}/oc-connected.XXXXXX")
JAR=$WORK/cookies

on_a() { orb -m "$A" sudo k3s kubectl "$@"; }
on_b() { orb -m "$B" sudo k3s kubectl "$@"; }
step() { echo "== $*"; }

tinker() {
    on_a -n opencloud exec deploy/dashboard -c web -- php artisan tinker --execute "$1" 2>/dev/null | tail -1
}

xsrf() {
    python3 -c 'import sys, urllib.parse
for line in open(sys.argv[1]):
    parts = line.rstrip("\n").split("\t")
    if len(parts) == 7 and parts[5] == "XSRF-TOKEN":
        print(urllib.parse.unquote(parts[6]))' "$JAR" | tail -1
}

# Prints the HTTP code and the Location header of a JSON POST to the dashboard.
post() {
    local path=$1 body=$2
    curl -s -o "$WORK/response" -D "$WORK/headers" -w '%{http_code}' -b "$JAR" -c "$JAR" \
        -H 'Content-Type: application/json' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $(xsrf)" \
        --data "$body" "$DASHBOARD$path"
    echo " $(grep -i '^location:' "$WORK/headers" | tr -d '\r' | awk '{ print $2 }')"
}

wait_for() {
    local seconds=$1 description=$2
    shift 2
    local deadline=$((SECONDS + seconds))
    until "$@"; do
        ((SECONDS < deadline)) || { echo "Timed out: $description" >&2; return 1; }
        sleep 5
    done
}

if [[ -z ${ENVIRONMENT_ID:-} ]]; then
step "Use the internal Caddy CA, because $HOST gets no public certificate"
on_a -n opencloud set env deployment/dashboard OPENCLOUD_TLS_ISSUER=internal >/dev/null
on_a -n opencloud rollout status deployment/dashboard --timeout=180s >/dev/null
dashboard_up() { [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$DASHBOARD/up") == 200 ]]; }
sleep 10
wait_for 180 "the dashboard answers" dashboard_up

step "Create the owner through $SETUP_URL"
curl -s -o /dev/null -c "$JAR" "$SETUP_URL"
post "${SETUP_URL#"$DASHBOARD"}" '{"name":"Owner","email":"owner@example.com","password":"correct-horse-battery","password_confirmation":"correct-horse-battery"}'

step "Connect $B ($B_IP) with its kubeconfig and its registry"
KUBECONFIG_TEXT=$(orb -m "$B" sudo cat /etc/rancher/k3s/k3s.yaml | sed "s#https://127.0.0.1:6443#https://$B_IP:6443#")
curl -s -o /dev/null -b "$JAR" -c "$JAR" "$DASHBOARD/clusters/create"
body=$(python3 -c 'import json, sys; print(json.dumps({"name": sys.argv[1], "kubeconfig": sys.argv[2], "registry_url": sys.argv[3], "registry_username": sys.argv[4], "registry_password": sys.argv[5], "registry_insecure": True}))' \
    "$B" "$KUBECONFIG_TEXT" "http://$B_IP:5000" "$REGISTRY_USER" "$REGISTRY_PASSWORD")
result=$(post /clusters "$body")
echo "POST /clusters: $result"
[[ $result == 302\ * ]] || { cat "$WORK/response"; exit 1; }
CLUSTER_ID=${result##*/}

cluster_status() { tinker "echo App\\Models\\Cluster::find($CLUSTER_ID)->status->value;"; }
cluster_ready() { [[ $(cluster_status) == ready ]]; }
wait_for 600 "the cluster becomes ready" cluster_ready || { tinker "echo App\\Models\\Cluster::find($CLUSTER_ID)->error;"; exit 1; }
tinker "\$c = App\\Models\\Cluster::find($CLUSTER_ID); echo json_encode([\$c->name, \$c->status, \$c->ingress_ip, \$c->parts_version]);"
on_b get pods,svc,pvc -n opencloud

step "Create laravel/laravel on $B with MySQL and $HOST"
curl -s -o /dev/null -b "$JAR" -c "$JAR" "$DASHBOARD/applications/create"
body=$(python3 -c 'import json, sys; print(json.dumps({"repository": "https://github.com/laravel/laravel", "name": sys.argv[1], "branch": "13.x", "cluster_id": int(sys.argv[2]), "type": "laravel", "size": "xs", "min_instances": 1, "max_instances": 3, "cpu_target": 20, "deploy_commands": ["php artisan migrate --force"], "worker_enabled": False, "scheduler_enabled": False, "domain": sys.argv[3], "database": "mysql"}))' \
    "$NAME" "$CLUSTER_ID" "$HOST")
result=$(post /applications "$body")
echo "POST /applications: $result"
[[ $result == 302\ * ]] || { cat "$WORK/response"; exit 1; }
ENVIRONMENT_ID=${result##*/}
else
step "Log in and deploy environment $ENVIRONMENT_ID again"
curl -s -o /dev/null -c "$JAR" "$DASHBOARD/login"
post /login '{"email":"owner@example.com","password":"correct-horse-battery"}'
echo "POST /environments/$ENVIRONMENT_ID/deployments: $(post "/environments/$ENVIRONMENT_ID/deployments" '{}')"
fi

deployment_status() { tinker "echo App\\Models\\Environment::find($ENVIRONMENT_ID)->deployments()->latest('id')->first()->status->value;"; }
deployment_done() { [[ $(deployment_status) =~ ^(live|failed)$ ]]; }
wait_for 1800 "the deployment finishes" deployment_done
on_a -n opencloud exec deploy/dashboard -c web -- php artisan tinker --execute "echo App\\Models\\Environment::find($ENVIRONMENT_ID)->deployments()->latest('id')->first()->log;" >"$WORK/deployment.log" 2>&1 || true
grep -E 'Copying|Building|live|failed' "$WORK/deployment.log" || true
[[ $(deployment_status) == live ]] || { tail -40 "$WORK/deployment.log"; exit 1; }

NAMESPACE=oc-env-$ENVIRONMENT_ID
on_b get pods -n oc-datastores
on_b get pods,hpa -n "$NAMESPACE"
on_b get deploy web -n "$NAMESPACE" -o jsonpath='{.spec.template.spec.containers[0].image} {.spec.template.spec.imagePullSecrets}{"\n"}'

domain_ok() { [[ $(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://$HOST/") == 200 ]]; }
wait_for 600 "https://$HOST/ answers 200" domain_ok
echo "curl -k https://$HOST/: $(curl -sk -o "$WORK/home.html" -w '%{http_code}' "https://$HOST/") $(grep -o '<title>[^<]*</title>' "$WORK/home.html")"

step "Stop $A and start a new web pod on $B"
orb stop "$A"
image=$(on_b get deploy web -n "$NAMESPACE" -o jsonpath='{.spec.template.spec.containers[0].image}')
orb -m "$B" sudo k3s crictl rmi "$image" || true
on_b delete pod -n "$NAMESPACE" -l opencloud.dev/process=web --wait=false
web_running() { on_b get pods -n "$NAMESPACE" -l opencloud.dev/process=web --field-selector=status.phase=Running -o name 2>/dev/null | grep -q pod/; }
wait_for 300 "a new web pod runs" web_running
on_b get pods -n "$NAMESPACE" -l opencloud.dev/process=web
on_b get events -n "$NAMESPACE" --field-selector reason=Pulled -o custom-columns=MESSAGE:.message | tail -2
echo "curl -k https://$HOST/ with $A stopped: $(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "https://$HOST/")"
echo "Environment: $ENVIRONMENT_ID. Namespace: $NAMESPACE. Logs: $WORK"

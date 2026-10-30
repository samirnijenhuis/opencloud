#!/usr/bin/env bash
# Runs as root on the test machine. Prints one line per target: "<name> open" or "<name> blocked".
# A pod in namespace default prints the lines that start with "default-". A machine without environments prints only those.
# A pod in an environment namespace prints the other lines. It needs two environments with a ready web Service that
# deployed after the NetworkPolicies came, because an environment gets its policies at its deployment.
# BuildKit has no network port, so it needs no check here.
set -euo pipefail

kc() { k3s kubectl "$@"; }

# Usage: probe <namespace> <name=host:port>...
probe() {
    local namespace=$1
    shift
    kc delete pod e2e-netcheck -n "$namespace" --ignore-not-found >/dev/null
    kc run e2e-netcheck -n "$namespace" --image=busybox --restart=Never --rm -i --quiet -- sh -c '
for target in "$@"; do
    name=${target%%=*}
    address=${target#*=}
    if nc -z -w 5 "${address%:*}" "${address##*:}"; then echo "$name open"; else echo "$name blocked"; fi
done' sh "$@"
}

environments=()
for namespace in $(kc get networkpolicy -A -l opencloud.dev/environment -o jsonpath='{range .items[*]}{.metadata.namespace}{"\n"}{end}' | sort -u); do
    if [[ -n $(kc get endpoints web -n "$namespace" --ignore-not-found -o jsonpath='{.subsets[0].addresses[0].ip}') ]]; then
        environments+=("$namespace")
    fi
done

datastore=$(kc get service -n oc-datastores -o jsonpath='{range .items[*]}{.metadata.name}:{.spec.ports[0].port}{"\n"}{end}' | sed -n 1p)
datastore_address=${datastore:+${datastore%:*}.oc-datastores.svc.cluster.local:${datastore##*:}}

dashboard=dashboard.opencloud.svc.cluster.local:8080
registry=registry.opencloud.svc.cluster.local:5000

from_default=("default-dashboard=$dashboard" "default-registry=$registry")
if [[ -n $datastore_address ]]; then
    from_default+=("default-datastore=$datastore_address")
fi
if ((${#environments[@]} >= 1)); then
    from_default+=("default-web=web.${environments[0]}.svc.cluster.local:8080")
fi
probe default "${from_default[@]}"

if ((${#environments[@]} < 2)); then
    echo "network-policy.sh: fewer than two environments with a ready web Service. The checks from an environment pod did not run." >&2
    exit 0
fi

node_ip=$(kc get endpoints kubernetes -n default -o jsonpath='{.subsets[0].addresses[0].ip}')
node_port=$(kc get endpoints kubernetes -n default -o jsonpath='{.subsets[0].ports[0].port}')

from_environment=(
    "own-web=web.${environments[0]}.svc.cluster.local:8080"
    "other-web=web.${environments[1]}.svc.cluster.local:8080"
    "dashboard=$dashboard"
    "registry=$registry"
    "kubernetes-api=kubernetes.default.svc.cluster.local:443"
    "node-api=$node_ip:$node_port"
    "caddy=caddy.opencloud.svc.cluster.local:443"
    # An app that calls its own domain reaches Caddy through ServiceLB on the node address. The except list holds that address too.
    "node-https=$node_ip:443"
)
if [[ -n $datastore_address ]]; then
    from_environment+=("datastore=$datastore_address")
fi
from_environment+=("internet=1.1.1.1:443")
probe "${environments[0]}" "${from_environment[@]}"

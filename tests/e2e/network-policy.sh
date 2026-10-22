#!/usr/bin/env bash
# Runs as root on the test machine. A pod in another namespace must reach the dashboard Service,
# but not BuildKit or the registry. Prints one line per target: "<name> open" or "<name> blocked".
set -euo pipefail

k3s kubectl delete pod e2e-netcheck -n default --ignore-not-found >/dev/null
k3s kubectl run e2e-netcheck -n default --image=busybox --restart=Never --rm -i --quiet -- sh -c '
for target in dashboard:8080 buildkitd:1234 registry:5000; do
    name=${target%%:*}
    port=${target##*:}
    if nc -z -w 5 "$name.opencloud.svc.cluster.local" "$port"; then echo "$name open"; else echo "$name blocked"; fi
done'

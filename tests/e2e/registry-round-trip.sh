#!/usr/bin/env bash
# Runs as root on the test machine. Builds an image with BuildKit from inside the dashboard pod,
# pushes it to the in-cluster registry, and runs a pod from it, so the node pulls through registries.yaml.
set -euo pipefail

ref=registry.opencloud.svc.cluster.local:5000/e2e/hello:1

k3s kubectl -n opencloud exec -i deploy/dashboard -c worker -- sh -eu -s "$ref" <<'EOF'
dir=$(mktemp -d)
printf 'FROM busybox\nCMD ["echo", "hello from the opencloud registry"]\n' >"$dir/Dockerfile"
buildctl --addr tcp://buildkitd.opencloud.svc.cluster.local:1234 build \
    --progress plain \
    --frontend dockerfile.v0 \
    --local context="$dir" \
    --local dockerfile="$dir" \
    --output "type=image,name=$1,push=true"
EOF

k3s kubectl -n default delete pod e2e-hello --ignore-not-found
k3s kubectl -n default run e2e-hello --image="$ref" --restart=Never
k3s kubectl -n default wait --for=jsonpath='{.status.phase}'=Succeeded pod/e2e-hello --timeout=120s
k3s kubectl -n default logs e2e-hello

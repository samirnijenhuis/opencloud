#!/usr/bin/env bash
# Release test. Installs opencloud on a brand-new OrbStack Ubuntu 24.04 machine and checks it from the Mac.
# Set KEEP=1 to keep the machine after the test.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MACHINE=oc-e2e-$(date +%s)
ARCH=$(docker version --format '{{.Server.Arch}}')
VERSION=e2e
IMAGE=ghcr.io/opencloud-dev/opencloud:$VERSION
TMP_ROOT=${TMPDIR:-/tmp}
WORK=$(mktemp -d "${TMP_ROOT%/}/oc-e2e.XXXXXX")
# OrbStack machines see the Mac file system under /mnt/mac.
REMOTE_SRC=/mnt/mac$ROOT
REMOTE_WORK=/mnt/mac$(cd "$WORK" && pwd -P)
INSTALL_LIMIT_SECONDS=300

RESULTS=()

record() {
    local name=$1 ok=$2
    if [[ $ok == 1 ]]; then RESULTS+=("PASS $name"); else RESULTS+=("FAIL $name"); fi
    echo "${RESULTS[${#RESULTS[@]} - 1]}"
}

on_machine() {
    orb -m "$MACHINE" sudo "$@"
}

cleanup() {
    rm -f "$WORK/opencloud.tar"
    if [[ ${KEEP:-0} == 1 ]]; then
        echo "KEEP=1: the machine $MACHINE stays. Delete it with: orb delete -f $MACHINE"
    else
        orb delete -f "$MACHINE" >/dev/null 2>&1 || true
    fi
    echo "Logs: $WORK"
}

run_install() {
    local log=$1
    on_machine env OPENCLOUD_VERSION="$VERSION" OPENCLOUD_IMAGE_ARCHIVE=/opt/opencloud/opencloud.tar \
        bash /opt/opencloud/install.sh >"$log" 2>&1
}

setup_url_lines() {
    grep '^Setup URL:' "$1" || true
}

echo "Building $IMAGE for linux/$ARCH"
docker build --platform "linux/$ARCH" -t "$IMAGE" "$ROOT/dashboard" >"$WORK/build.log" 2>&1 ||
    { tail -50 "$WORK/build.log"; exit 1; }
docker save --platform "linux/$ARCH" -o "$WORK/opencloud.tar" "$IMAGE"

echo "Creating the machine $MACHINE"
orb create "ubuntu:noble" "$MACHINE" >/dev/null
trap cleanup EXIT
IP=$(orb -m "$MACHINE" hostname -I | awk '{ print $1 }')

on_machine mkdir -p /opt/opencloud
on_machine cp -R "$REMOTE_SRC/install.sh" "$REMOTE_SRC/platform" "$REMOTE_SRC/bin" "$REMOTE_WORK/opencloud.tar" /opt/opencloud/

echo "Running install.sh on $MACHINE ($IP)"
start=$(date +%s)
first_status=0
run_install "$WORK/install-1.log" || first_status=$?
seconds=$(($(date +%s) - start))
echo "Install exit code: $first_status. Install time: ${seconds}s."
[[ $first_status == 0 ]] || tail -40 "$WORK/install-1.log"

first_url=$(setup_url_lines "$WORK/install-1.log")
ok=0
[[ $first_status == 0 && $first_url =~ ^Setup\ URL:\ http://$IP:8000/setup/[0-9a-f]{64}$ ]] && ok=1
record "the installer prints one Setup URL line ($first_url)" "$ok"

ok=0
[[ $first_status == 0 ]] && ((seconds < INSTALL_LIMIT_SECONDS)) && ok=1
record "the install takes less than ${INSTALL_LIMIT_SECONDS}s (${seconds}s)" "$ok"

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://$IP:8000/up" || true)
ok=0
[[ $code == 200 ]] && ok=1
record "the Mac gets 200 from http://$IP:8000/up (got $code)" "$ok"

setup_path=${first_url#Setup URL: http://$IP:8000}
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://$IP:8000$setup_path" || true)
ok=0
[[ $code == 200 ]] && ok=1
record "the setup page answers 200 with the printed token (got $code)" "$ok"

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://$IP:8000/setup/wrong-token" || true)
ok=0
[[ $code == 404 ]] && ok=1
record "the setup page answers 404 with a wrong token (got $code)" "$ok"

ok=0
if on_machine bash "$REMOTE_SRC/tests/e2e/registry-round-trip.sh" >"$WORK/registry.log" 2>&1; then
    grep -q '^hello from the opencloud registry$' "$WORK/registry.log" && ok=1
fi
[[ $ok == 1 ]] || tail -30 "$WORK/registry.log"
record "a BuildKit image goes through the registry and runs as a pod" "$ok"

second_status=0
run_install "$WORK/install-2.log" || second_status=$?
second_url=$(setup_url_lines "$WORK/install-2.log")
ok=0
[[ $second_status == 0 && -n $first_url && $second_url == "$first_url" ]] && ok=1
[[ $second_status == 0 ]] || tail -40 "$WORK/install-2.log"
record "a second install.sh run exits 0 and prints the same Setup URL" "$ok"

echo "Install time: ${seconds}s"
! printf '%s\n' "${RESULTS[@]}" | grep -q '^FAIL'

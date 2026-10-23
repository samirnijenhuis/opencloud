#!/usr/bin/env bash
# Release test for the one-line install. Serves a locally built release archive over HTTP from the Mac,
# runs `curl | bash` on a brand-new OrbStack Ubuntu 24.04 machine, and checks the result.
# Set KEEP=1 to keep the machine after the test.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MACHINE=oc-curl-$(date +%s)
ARCH=$(docker version --format '{{.Server.Arch}}')
VERSION=v0.0.0-e2e
IMAGE=ghcr.io/opencloud-dev/opencloud:$VERSION
CADDY_IMAGE=ghcr.io/opencloud-dev/opencloud-caddy:$VERSION
TMP_ROOT=${TMPDIR:-/tmp}
WORK=$(mktemp -d "${TMP_ROOT%/}/oc-curl.XXXXXX")
SERVE=$WORK/serve
mkdir -p "$SERVE"
# OrbStack machines see the Mac file system under /mnt/mac.
REMOTE_WORK=/mnt/mac$(cd "$WORK" && pwd -P)

RESULTS=()
SERVER_PID=

record() {
    local name=$1 ok=$2
    if [[ $ok == 1 ]]; then RESULTS+=("PASS $name"); else RESULTS+=("FAIL $name"); fi
    echo "${RESULTS[${#RESULTS[@]} - 1]}"
}

cleanup() {
    [[ -z $SERVER_PID ]] || kill "$SERVER_PID" 2>/dev/null || true
    rm -f "$WORK/opencloud.tar"
    if [[ ${KEEP:-0} == 1 ]]; then
        echo "KEEP=1: the machine $MACHINE stays. Delete it with: orb delete -f $MACHINE"
    else
        orb delete -f "$MACHINE" >/dev/null 2>&1 || true
    fi
    echo "Logs: $WORK"
}

echo "Building $IMAGE for linux/$ARCH"
docker build --platform "linux/$ARCH" --build-context tofu="$ROOT/tofu" -t "$IMAGE" "$ROOT/dashboard" >"$WORK/build.log" 2>&1 ||
    { tail -50 "$WORK/build.log"; exit 1; }
docker save --platform "linux/$ARCH" -o "$WORK/opencloud.tar" "$IMAGE"

echo "Building $CADDY_IMAGE for linux/$ARCH"
docker build --platform "linux/$ARCH" -t "$CADDY_IMAGE" "$ROOT/platform/images/caddy" >"$WORK/build-caddy.log" 2>&1 ||
    { tail -50 "$WORK/build-caddy.log"; exit 1; }
docker save --platform "linux/$ARCH" -o "$WORK/caddy.tar" "$CADDY_IMAGE"

"$ROOT/tests/e2e/build-release-archive.sh" "$VERSION" "$SERVE" >/dev/null
cp "$ROOT/install.sh" "$SERVE/install.sh"

PORT=$(python3 -c 'import socket; s = socket.socket(); s.bind(("", 0)); print(s.getsockname()[1])')
python3 -m http.server "$PORT" --directory "$SERVE" >"$WORK/http.log" 2>&1 &
SERVER_PID=$!
BASE=http://host.orb.internal:$PORT

echo "Creating the machine $MACHINE"
trap cleanup EXIT
orb create "ubuntu:noble" "$MACHINE" >/dev/null
IP=$(orb -m "$MACHINE" hostname -I | awk '{ print $1 }')

orb -m "$MACHINE" sudo mkdir -p /opt/oc-test
orb -m "$MACHINE" sudo cp "$REMOTE_WORK/opencloud.tar" /opt/oc-test/opencloud.tar
orb -m "$MACHINE" sudo cp "$REMOTE_WORK/caddy.tar" /opt/oc-test/caddy.tar

echo "Running curl | bash on $MACHINE ($IP) from $BASE"
status=0
orb -m "$MACHINE" sudo bash -c "curl -fsSL $BASE/install.sh | OPENCLOUD_RELEASE_URL=$BASE OPENCLOUD_VERSION=$VERSION OPENCLOUD_IMAGE_ARCHIVE=/opt/oc-test/opencloud.tar OPENCLOUD_CADDY_IMAGE_ARCHIVE=/opt/oc-test/caddy.tar bash" \
    >"$WORK/install.log" 2>&1 || status=$?
echo "Install exit code: $status."
[[ $status == 0 ]] || tail -40 "$WORK/install.log"

url=$(grep '^Setup URL:' "$WORK/install.log" || true)
ok=0
[[ $status == 0 && $url =~ ^Setup\ URL:\ http://$IP:8000/setup/[0-9a-f]{64}$ ]] && ok=1
record "curl | bash prints one Setup URL line ($url)" "$ok"

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://$IP:8000/up" || true)
ok=0
[[ $code == 200 ]] && ok=1
record "the Mac gets 200 from http://$IP:8000/up (got $code)" "$ok"

ok=0
orb -m "$MACHINE" sudo test -f "/opt/opencloud/$VERSION/platform/networkpolicies.yaml" && ok=1
record "the archive was extracted to /opt/opencloud/$VERSION" "$ok"

! printf '%s\n' "${RESULTS[@]}" | grep -q '^FAIL'

#!/usr/bin/env bash
# Runs opencloud on this Mac in an OrbStack Ubuntu 24.04 machine, built from this checkout.
# Run it again to update the machine to your current code. The data on the machine stays.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
MACHINE=${OPENCLOUD_MACHINE:-opencloud}
VERSION=dev-$(date +%Y%m%d%H%M%S)
WORK=$ROOT/.try
IMAGE=ghcr.io/opencloud-dev/opencloud:$VERSION
CADDY_IMAGE=ghcr.io/opencloud-dev/opencloud-caddy:$VERSION

step() { printf '\n==> %s\n' "$1"; }
fail() {
    printf '\nError: %s\n' "$1" >&2
    exit 1
}

[[ $(uname -s) == Darwin ]] || fail "This script is for macOS. On a Linux server, run install.sh as root."
command -v orb >/dev/null || fail "OrbStack is missing. Install it from https://orbstack.dev, open it once, and run this script again."
docker info >/dev/null 2>&1 || fail "Docker does not answer. Open OrbStack and run this script again."
ARCH=$(docker version --format '{{.Server.Arch}}')
mkdir -p "$WORK"

step "Building the dashboard image for linux/$ARCH. The first build takes a few minutes."
docker build --platform "linux/$ARCH" --build-context tofu="$ROOT/tofu" -t "$IMAGE" "$ROOT/dashboard" >"$WORK/build.log" 2>&1 ||
    { tail -30 "$WORK/build.log"; fail "The dashboard image did not build. The full log is in $WORK/build.log."; }
docker save --platform "linux/$ARCH" -o "$WORK/opencloud.tar" "$IMAGE"

step "Building the Caddy image"
docker build --platform "linux/$ARCH" -t "$CADDY_IMAGE" "$ROOT/platform/images/caddy" >"$WORK/build-caddy.log" 2>&1 ||
    { tail -30 "$WORK/build-caddy.log"; fail "The Caddy image did not build. The full log is in $WORK/build-caddy.log."; }
docker save --platform "linux/$ARCH" -o "$WORK/caddy.tar" "$CADDY_IMAGE"
# The archives hold the images now. Docker keeps the build cache, so the next build is fast.
docker image rm "$IMAGE" "$CADDY_IMAGE" >/dev/null

if orb list | awk '{ print $1 }' | grep -qx "$MACHINE"; then
    step "Starting the machine $MACHINE"
    orb start "$MACHINE" >/dev/null 2>&1 || true
else
    step "Creating the machine $MACHINE (Ubuntu 24.04)"
    orb create ubuntu:noble "$MACHINE" >/dev/null
fi

step "Installing opencloud $VERSION on $MACHINE"
target=/opt/opencloud-dev/$VERSION
orb -m "$MACHINE" sudo mkdir -p "$target"
# OrbStack machines see the Mac file system under /mnt/mac.
orb -m "$MACHINE" sudo cp -R "/mnt/mac$ROOT/install.sh" "/mnt/mac$ROOT/platform" "/mnt/mac$ROOT/bin" "/mnt/mac$WORK/opencloud.tar" "/mnt/mac$WORK/caddy.tar" "$target/"
# Let's Encrypt cannot reach a machine on this Mac, so Caddy uses its own local CA.
orb -m "$MACHINE" sudo env OPENCLOUD_VERSION="$VERSION" OPENCLOUD_TLS_ISSUER=internal \
    OPENCLOUD_IMAGE_ARCHIVE="$target/opencloud.tar" OPENCLOUD_CADDY_IMAGE_ARCHIVE="$target/caddy.tar" \
    bash "$target/install.sh" 2>&1 | tee "$WORK/install.log" ||
    fail "The installer stopped. Its output is above and in $WORK/install.log."
# k3s has imported the images, so the archives on the machine are no longer needed.
orb -m "$MACHINE" sudo rm -f "$target/opencloud.tar" "$target/caddy.tar"

ip=$(sed -E -n 's#^(Setup URL|Dashboard): http://([0-9.]+):8000.*#\2#p' "$WORK/install.log" | tail -1)

step "Opencloud runs on $MACHINE"
cat <<EOF
Open the address above in your browser.

For your first app, use these values in Applications > New application:
  Repository   laravel/laravel   (leave the branch empty)
  Domain       myapp.$ip.sslip.io

Your browser warns about the certificate of the app, because Caddy uses a local CA here.
docs/run-on-your-mac.md tells you how to trust that CA.

Commands:
  orb -m $MACHINE sudo opencloud status    Show the opencloud pods.
  orb stop $MACHINE                        Stop the machine. orb start $MACHINE starts it again.
  orb delete -f $MACHINE                   Delete the machine and all its data.
EOF

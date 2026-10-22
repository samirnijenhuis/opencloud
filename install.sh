#!/usr/bin/env bash
# Installs opencloud on a fresh Ubuntu 24.04 server. Run it again to repair or update an install.
set -euo pipefail

K3S_VERSION=v1.36.5+k3s1
OPENCLOUD_REPO=${OPENCLOUD_REPO:-opencloud-dev/opencloud}
OPENCLOUD_VERSION=${OPENCLOUD_VERSION:-latest}
OPENCLOUD_IMAGE=${OPENCLOUD_IMAGE:-ghcr.io/${OPENCLOUD_REPO%%/*}/opencloud:$OPENCLOUD_VERSION}
OPENCLOUD_TLS_ISSUER=${OPENCLOUD_TLS_ISSUER:-acme}
STATE_DIR=/var/lib/opencloud
# When bash reads the script from a pipe, no file backs it, so nothing sits beside it. The current folder does not count.
if [[ -f ${BASH_SOURCE[0]:-} ]]; then
    SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
else
    SOURCE_DIR=
fi
PLATFORM_DIR=$SOURCE_DIR/platform

say() {
    printf '==> %s\n' "$*"
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

kc() {
    k3s kubectl "$@"
}

server_ip() {
    if [[ -n ${OPENCLOUD_PUBLIC_IP:-} ]]; then
        echo "$OPENCLOUD_PUBLIC_IP"
        return
    fi
    local dev
    dev=$(ip -4 route show default | awk '{ for (i = 1; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }')
    [[ -n $dev ]] || return 0
    ip -4 -o addr show dev "$dev" scope global | awk '{ split($4, a, "/"); print a[1]; exit }'
}

port_holders() {
    ss -Htlnp "sport = :$1" | grep -o '(("[^"]*"' | cut -d'"' -f2 | sort -u
}

# The curl | bash case: no files sit beside the script, so fetch the release archive and run the installer from it.
bootstrap() {
    [[ -z $SOURCE_DIR || ! -d $PLATFORM_DIR ]] || return 0

    [[ $EUID -eq 0 ]] || fail "Run the installer as root, for example: curl -fsSL <url>/install.sh | sudo bash"
    local tool
    for tool in curl tar sha256sum; do
        command -v "$tool" >/dev/null || fail "The command $tool is missing. Install it, for example: apt-get install -y curl"
    done

    local base=${OPENCLOUD_RELEASE_URL:-}
    if [[ -z $base ]]; then
        if [[ $OPENCLOUD_VERSION == latest ]]; then
            base=https://github.com/$OPENCLOUD_REPO/releases/latest/download
        else
            base=https://github.com/$OPENCLOUD_REPO/releases/download/$OPENCLOUD_VERSION
        fi
    fi

    local name=opencloud-$OPENCLOUD_VERSION.tar.gz tmp
    tmp=$(mktemp -d)
    say "Downloading $base/$name"
    curl -fsSL -o "$tmp/$name" "$base/$name" || fail "Cannot download $base/$name. Check the version and the network."
    curl -fsSL -o "$tmp/$name.sha256" "$base/$name.sha256" || fail "Cannot download $base/$name.sha256."
    (cd "$tmp" && sha256sum -c "$name.sha256" >/dev/null) || fail "The checksum of $name does not match $name.sha256. Do not install this archive."

    local dir=/opt/opencloud/$OPENCLOUD_VERSION
    install -d -m 0755 "$dir"
    tar -xzf "$tmp/$name" -C "$dir"
    say "Running the installer from $dir"
    exec bash "$dir/install.sh" "$@"
}

preflight() {
    [[ $EUID -eq 0 ]] || fail "Run the installer as root, for example: sudo bash install.sh"

    [[ -d $PLATFORM_DIR && -f $SOURCE_DIR/bin/opencloud ]] ||
        fail "Cannot find platform/ and bin/opencloud next to install.sh. Run install.sh from the opencloud release folder."

    local os="unknown"
    # shellcheck source=/dev/null
    [[ -r /etc/os-release ]] && os=$(. /etc/os-release && echo "${ID:-} ${VERSION_ID:-}")
    if [[ $os != "ubuntu 24.04" && ${OPENCLOUD_SKIP_OS_CHECK:-0} != 1 ]]; then
        fail "Opencloud supports Ubuntu 24.04 only. This server runs $os. Use Ubuntu 24.04, or set OPENCLOUD_SKIP_OS_CHECK=1 to try at your own risk."
    fi

    local tool
    for tool in curl ss ip; do
        command -v "$tool" >/dev/null || fail "The command $tool is missing. Install it, for example: apt-get install -y curl iproute2"
    done

    local cpus
    cpus=$(nproc)
    ((cpus >= 2)) || fail "Opencloud needs at least 2 CPUs. This server has $cpus. Use a larger server."

    local mem_mib
    mem_mib=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
    ((mem_mib >= 3500)) || fail "Opencloud needs at least 3500 MiB of RAM (a 4 GB server). This server has $mem_mib MiB. Use a larger server."

    local port holders
    for port in 80 443 8000 6443; do
        holders=$(port_holders "$port" | grep -v '^k3s' || true)
        [[ -z $holders ]] || fail "Port $port is in use by ${holders//$'\n'/, }. Stop that program, then run the installer again. To see it, run: ss -tlnp 'sport = :$port'"
    done

    local url
    for url in https://get.k3s.io https://github.com https://registry-1.docker.io/v2/; do
        curl -sS -o /dev/null --max-time 15 "$url" 2>/dev/null ||
            fail "Cannot reach $url. Give the server outbound internet access on port 443."
    done

    if [[ -n ${OPENCLOUD_IMAGE_ARCHIVE:-} && ! -f $OPENCLOUD_IMAGE_ARCHIVE ]]; then
        fail "OPENCLOUD_IMAGE_ARCHIVE points to $OPENCLOUD_IMAGE_ARCHIVE, but that file does not exist."
    fi

    SERVER_IP=$(server_ip)
    [[ -n $SERVER_IP ]] || fail "Cannot find the IP address of this server. Set OPENCLOUD_PUBLIC_IP to the public IP address."
}

install_k3s() {
    local file changed=0
    for file in config.yaml registries.yaml; do
        if ! cmp -s "$PLATFORM_DIR/k3s/$file" "/etc/rancher/k3s/$file"; then
            install -D -m 0600 "$PLATFORM_DIR/k3s/$file" "/etc/rancher/k3s/$file"
            changed=1
        fi
    done

    if [[ $(k3s --version 2>/dev/null) != *"$K3S_VERSION"* ]]; then
        say "Installing k3s $K3S_VERSION"
        curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION=$K3S_VERSION sh -
    elif ((changed)); then
        say "Restarting k3s to apply the new k3s configuration"
        systemctl restart k3s
    else
        systemctl start k3s
    fi

    say "Waiting for the Kubernetes node"
    local _
    for _ in $(seq 1 90); do
        if [[ $(kc get nodes -o 'jsonpath={.items[*].status.conditions[?(@.type=="Ready")].status}' 2>/dev/null) == True ]]; then
            return
        fi
        sleep 2
    done
    fail "k3s did not become ready in 180 seconds. Check the k3s log with: journalctl -u k3s"
}

ensure_secrets() {
    install -d -m 0700 "$STATE_DIR"
    if [[ ! -s $STATE_DIR/APP_KEY && -s $STATE_DIR/data/database.sqlite ]]; then
        fail "$STATE_DIR/APP_KEY is missing, but the dashboard database exists. A new key cannot read the stored secrets. Put the saved APP_KEY back in $STATE_DIR/APP_KEY and run the installer again."
    fi
    (
        umask 077
        [[ -s $STATE_DIR/APP_KEY ]] || printf 'base64:%s' "$(head -c 32 /dev/urandom | base64)" >"$STATE_DIR/APP_KEY"
        [[ -s $STATE_DIR/setup-token ]] || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$STATE_DIR/setup-token"
    )
    kc apply -f "$PLATFORM_DIR/namespace.yaml"
    kc -n opencloud create secret generic dashboard \
        --from-file=APP_KEY="$STATE_DIR/APP_KEY" \
        --from-file=OPENCLOUD_SETUP_TOKEN="$STATE_DIR/setup-token" \
        --dry-run=client -o yaml | kc apply -f -
}

load_dashboard_image() {
    [[ -n ${OPENCLOUD_IMAGE_ARCHIVE:-} ]] || return 0
    say "Importing the dashboard image from $OPENCLOUD_IMAGE_ARCHIVE"
    k3s ctr images import "$OPENCLOUD_IMAGE_ARCHIVE"
    k3s ctr images ls -q | awk -v ref="$OPENCLOUD_IMAGE" '$0 == ref || substr($0, length($0) - length(ref)) == "/" ref { found = 1 } END { exit !found }' ||
        fail "The archive $OPENCLOUD_IMAGE_ARCHIVE does not contain the image $OPENCLOUD_IMAGE. Set OPENCLOUD_IMAGE to the image name in the archive."
}

apply_platform() {
    say "Applying the opencloud manifests"
    kc apply -f "$PLATFORM_DIR/rbac.yaml" -f "$PLATFORM_DIR/registry.yaml" -f "$PLATFORM_DIR/buildkitd.yaml" -f "$PLATFORM_DIR/caddy.yaml" -f "$PLATFORM_DIR/networkpolicies.yaml"
    sed -e "s|__DASHBOARD_IMAGE__|$OPENCLOUD_IMAGE|g" \
        -e "s|__APP_URL__|http://$SERVER_IP:8000|g" \
        -e "s|__OPENCLOUD_VERSION__|$OPENCLOUD_VERSION|g" \
        -e "s|__TLS_ISSUER__|$OPENCLOUD_TLS_ISSUER|g" \
        -e "s|__PUBLIC_IP__|$SERVER_IP|g" \
        "$PLATFORM_DIR/dashboard.yaml" | kc apply -f -

    say "Waiting for the opencloud pods"
    local deployment
    for deployment in registry buildkitd caddy dashboard; do
        kc -n opencloud rollout status "deployment/$deployment" --timeout=240s ||
            fail "The $deployment deployment is not ready. Check it with: opencloud status, and kubectl -n opencloud describe deployment/$deployment"
    done
}

configure_caddy() {
    local current
    current=$(kc -n opencloud exec deploy/caddy -- wget -q -O- http://127.0.0.1:2019/config/) ||
        fail "Cannot read the Caddy config. Check it with: opencloud logs caddy"
    # Only an empty Caddy gets the bootstrap config. After setup the dashboard owns the full config.
    if [[ $current == null ]]; then
        say "Loading the bootstrap Caddy config"
        kc -n opencloud exec -i deploy/caddy -- sh -c 'cat >/tmp/bootstrap.json && wget -q -O- --header "Content-Type: application/json" --post-file /tmp/bootstrap.json http://127.0.0.1:2019/load' \
            <"$PLATFORM_DIR/caddy-bootstrap.json"
    fi

    local _
    for _ in $(seq 1 30); do
        curl -fsS -o /dev/null --max-time 5 http://127.0.0.1:8000/up 2>/dev/null && return
        sleep 2
    done
    fail "The dashboard does not answer on port 8000. Check it with: opencloud logs caddy, and opencloud logs web"
}

main() {
    bootstrap "$@"
    preflight
    install_k3s
    ensure_secrets
    load_dashboard_image
    apply_platform
    configure_caddy
    install -m 0755 "$SOURCE_DIR/bin/opencloud" /usr/local/bin/opencloud

    echo
    echo "Opencloud is running."
    opencloud setup-url
    echo "The APP_KEY is in $STATE_DIR/APP_KEY. Keep a copy in a safe place. Without it, the dashboard cannot read its encrypted data."
}

main "$@"

#!/usr/bin/env bash
# Installs opencloud on a fresh Ubuntu 24.04 server. Run it again to repair or update an install.
set -euo pipefail

K3S_VERSION=v1.36.5+k3s1
# The SHA-256 of the k3s install script at the tag K3S_VERSION. Change both together. To get the sum, run:
# curl -fsSL "https://raw.githubusercontent.com/k3s-io/k3s/<K3S_VERSION with + as %2B>/install.sh" | sha256sum
K3S_INSTALL_SHA256=46177d4c99440b4c0311b67233823a8e8a2fc09693f6c89af1a7161e152fbfad
OPENCLOUD_REPO=${OPENCLOUD_REPO:-opencloud-dev/opencloud}
OPENCLOUD_VERSION=${OPENCLOUD_VERSION:-latest}
OPENCLOUD_IMAGE=${OPENCLOUD_IMAGE:-ghcr.io/${OPENCLOUD_REPO%%/*}/opencloud:$OPENCLOUD_VERSION}
OPENCLOUD_CADDY_IMAGE=${OPENCLOUD_CADDY_IMAGE:-ghcr.io/${OPENCLOUD_REPO%%/*}/opencloud-caddy:$OPENCLOUD_VERSION}
OPENCLOUD_TLS_ISSUER=${OPENCLOUD_TLS_ISSUER:-acme}
STATE_DIR=/var/lib/opencloud
# The output of k3s, ctr, and kubectl goes here, so the terminal shows only the installer's own steps.
LOG=/var/log/opencloud-install.log
# Restore mode: a file with the backup bucket settings and the saved APP_KEY. See "Restore a lost server" in README.md.
RESTORE_FILE=${OPENCLOUD_RESTORE_FILE:-}
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

# Usage: logged <command...>. Runs the command with its output in the install log.
logged() {
    "$@" >>"$LOG" 2>&1
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

# Usage: latest_release <owner/repository>. Prints the tag of the newest release, for example v1.2.0.
latest_release() {
    local tag
    tag=$(curl -fsSL --max-time 20 "https://api.github.com/repos/$1/releases/latest" | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p')
    [[ -n $tag ]] && echo "$tag"
}

# The curl | bash case: no files sit beside the script, so fetch the release archive and run the installer from it.
bootstrap() {
    [[ -z $SOURCE_DIR || ! -d $PLATFORM_DIR ]] || return 0

    [[ $EUID -eq 0 ]] || fail "Run the installer as root, for example: curl -fsSL <url>/install.sh | sudo bash"
    local tool
    for tool in curl tar sha256sum; do
        command -v "$tool" >/dev/null || fail "The command $tool is missing. Install it, for example: apt-get install -y curl"
    done

    # A fixed version lets the dashboard and opencloud upgrade compare it with newer releases.
    if [[ $OPENCLOUD_VERSION == latest ]]; then
        OPENCLOUD_VERSION=$(latest_release "$OPENCLOUD_REPO") ||
            fail "Cannot find the latest release of $OPENCLOUD_REPO. Set the version, for example: OPENCLOUD_VERSION=v1.0.0"
        export OPENCLOUD_VERSION
    fi
    local base=${OPENCLOUD_RELEASE_URL:-https://github.com/$OPENCLOUD_REPO/releases/download/$OPENCLOUD_VERSION}

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
    for url in https://raw.githubusercontent.com https://github.com https://registry-1.docker.io/v2/; do
        # A new server can need a few seconds before DNS and routing work.
        curl -sS -o /dev/null --max-time 15 --retry 4 --retry-delay 5 --retry-all-errors "$url" 2>/dev/null ||
            fail "Cannot reach $url. Give the server outbound internet access on port 443."
    done

    if [[ -n ${OPENCLOUD_IMAGE_ARCHIVE:-} && ! -f $OPENCLOUD_IMAGE_ARCHIVE ]]; then
        fail "OPENCLOUD_IMAGE_ARCHIVE points to $OPENCLOUD_IMAGE_ARCHIVE, but that file does not exist."
    fi
    if [[ -n ${OPENCLOUD_CADDY_IMAGE_ARCHIVE:-} && ! -f $OPENCLOUD_CADDY_IMAGE_ARCHIVE ]]; then
        fail "OPENCLOUD_CADDY_IMAGE_ARCHIVE points to $OPENCLOUD_CADDY_IMAGE_ARCHIVE, but that file does not exist."
    fi

    SERVER_IP=$(server_ip)
    [[ -n $SERVER_IP ]] || fail "Cannot find the IP address of this server. Set OPENCLOUD_PUBLIC_IP to the public IP address."

    if [[ -n $RESTORE_FILE ]]; then
        [[ -f $RESTORE_FILE ]] || fail "OPENCLOUD_RESTORE_FILE points to $RESTORE_FILE, but that file does not exist."
        RESTORE_FILE=$(realpath "$RESTORE_FILE")
        # restore.json shows that the database came from an earlier run of this restore, so the run can go on.
        if [[ -s $STATE_DIR/data/database.sqlite && ! -f $STATE_DIR/data/restore.json ]]; then
            fail "This server already has a dashboard database in $STATE_DIR/data. Restore mode works only on a new server. To repair this install, run the installer without OPENCLOUD_RESTORE_FILE."
        fi
    fi
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
        # get.k3s.io serves the newest script, so the installer runs the script of the pinned release and checks it first.
        local script
        script=$(mktemp)
        curl -fsSL -o "$script" "https://raw.githubusercontent.com/k3s-io/k3s/${K3S_VERSION/+/%2B}/install.sh" ||
            fail "Cannot download the k3s install script of $K3S_VERSION."
        echo "$K3S_INSTALL_SHA256  $script" | sha256sum -c - >/dev/null ||
            fail "The k3s install script does not match its pinned checksum. Do not install it."
        INSTALL_K3S_VERSION=$K3S_VERSION sh "$script" >>"$LOG" 2>&1 ||
            fail "k3s did not install. The details are in $LOG."
        rm -f "$script"
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
    logged kc apply -f "$PLATFORM_DIR/namespace.yaml" || fail "Cannot create the opencloud namespace. The details are in $LOG."
    kc -n opencloud create secret generic dashboard \
        --from-file=APP_KEY="$STATE_DIR/APP_KEY" \
        --from-file=OPENCLOUD_SETUP_TOKEN="$STATE_DIR/setup-token" \
        --dry-run=client -o yaml | logged kc apply -f - || fail "Cannot store the dashboard Secret. The details are in $LOG."
}

# Restore mode: a one-off Job with the dashboard image brings the database and the APP_KEY back from the bucket.
restore_dashboard() {
    [[ -n $RESTORE_FILE ]] || return 0
    if [[ -s $STATE_DIR/data/database.sqlite ]]; then
        say "The dashboard database is already restored"
        return
    fi

    say "Restoring the dashboard database from the backup bucket"
    install -d -m 0700 "$STATE_DIR"
    kc apply -f "$PLATFORM_DIR/namespace.yaml" >/dev/null
    kc -n opencloud delete job restore --ignore-not-found --wait=true >/dev/null
    # The node is ready before the cluster DNS, and the Job must resolve the bucket endpoint.
    local result=timeout _
    for _ in $(seq 1 18); do
        kc -n kube-system rollout status deployment/coredns --timeout=10s >/dev/null 2>&1 && break
        sleep 2
    done
    sed -e "s|__DASHBOARD_IMAGE__|$OPENCLOUD_IMAGE|g" -e "s|__RESTORE_FILE__|$RESTORE_FILE|g" "$PLATFORM_DIR/restore.yaml" | kc apply -f - >/dev/null

    for _ in $(seq 1 60); do
        if kc -n opencloud wait --for=condition=complete job/restore --timeout=10s >/dev/null 2>&1; then
            result=complete
            break
        fi
        if kc -n opencloud wait --for=condition=failed job/restore --timeout=0s >/dev/null 2>&1; then
            result=failed
            break
        fi
    done
    kc -n opencloud logs --selector=job-name=restore --tail=-1 2>/dev/null || true

    case $result in
    complete) kc -n opencloud delete job restore --wait=false >/dev/null ;;
    failed) fail "The dashboard database was not restored. Read the message above, fix the restore file, and run the installer again." ;;
    *) fail "The restore Job did not finish in 10 minutes. Check it with: k3s kubectl -n opencloud describe job/restore" ;;
    esac
}

start_restore() {
    kc -n opencloud exec deploy/dashboard -c web -- php artisan opencloud:restore ||
        fail "The restore did not start. Check it with: opencloud logs web"
    echo
    echo "Opencloud runs on the restored dashboard database at http://$SERVER_IP:8000."
    echo "The restore goes on in the background. Follow it with: opencloud restore-status"
    echo "Point the DNS records of your domains at $SERVER_IP."
    echo "The file $RESTORE_FILE holds the APP_KEY and the keys of the bucket. Delete it, or keep it in a safe place."
}

# Usage: import_image <name> <archive> <image> <variable that sets the image>
import_image() {
    local name=$1 archive=$2 image=$3 variable=$4
    [[ -n $archive ]] || return 0
    say "Importing the $name image from $archive"
    logged k3s ctr images import "$archive" || fail "Cannot import $archive. The details are in $LOG."
    k3s ctr images ls -q | awk -v ref="$image" '$0 == ref || substr($0, length($0) - length(ref)) == "/" ref { found = 1 } END { exit !found }' ||
        fail "The archive $archive does not contain the image $image. Set $variable to the image name in the archive."
}

load_images() {
    import_image dashboard "${OPENCLOUD_IMAGE_ARCHIVE:-}" "$OPENCLOUD_IMAGE" OPENCLOUD_IMAGE
    import_image Caddy "${OPENCLOUD_CADDY_IMAGE_ARCHIVE:-}" "$OPENCLOUD_CADDY_IMAGE" OPENCLOUD_CADDY_IMAGE
}

apply_platform() {
    say "Applying the opencloud manifests"
    logged kc apply -f "$PLATFORM_DIR/rbac.yaml" -f "$PLATFORM_DIR/registry.yaml" -f "$PLATFORM_DIR/buildkitd.yaml" -f "$PLATFORM_DIR/networkpolicies.yaml" ||
        fail "Cannot apply the opencloud manifests. The details are in $LOG."
    sed -e "s|__CADDY_IMAGE__|$OPENCLOUD_CADDY_IMAGE|g" "$PLATFORM_DIR/caddy.yaml" | logged kc apply -f - ||
        fail "Cannot apply the Caddy manifest. The details are in $LOG."
    sed -e "s|__DASHBOARD_IMAGE__|$OPENCLOUD_IMAGE|g" \
        -e "s|__APP_URL__|http://$SERVER_IP:8000|g" \
        -e "s|__OPENCLOUD_VERSION__|$OPENCLOUD_VERSION|g" \
        -e "s|__OPENCLOUD_REPO__|$OPENCLOUD_REPO|g" \
        -e "s|__TLS_ISSUER__|$OPENCLOUD_TLS_ISSUER|g" \
        -e "s|__PUBLIC_IP__|$SERVER_IP|g" \
        "$PLATFORM_DIR/dashboard.yaml" | logged kc apply -f - ||
        fail "Cannot apply the dashboard manifest. The details are in $LOG."

    say "Waiting for the opencloud pods"
    local deployment
    for deployment in registry buildkitd caddy dashboard; do
        logged kc -n opencloud rollout status "deployment/$deployment" --timeout=240s ||
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
        logged kc -n opencloud exec -i deploy/caddy -- sh -c 'cat >/tmp/bootstrap.json && wget -q -O- --header "Content-Type: application/json" --post-file /tmp/bootstrap.json http://127.0.0.1:2019/load' \
            <"$PLATFORM_DIR/caddy-bootstrap.json" || fail "Caddy refused the bootstrap config. The details are in $LOG."
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
    printf '\n===== %s: install of opencloud %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$OPENCLOUD_VERSION" >>"$LOG"
    say "Installing opencloud $OPENCLOUD_VERSION. The detailed output goes to $LOG."
    install_k3s
    load_images
    restore_dashboard
    ensure_secrets
    apply_platform
    configure_caddy
    install -m 0755 "$SOURCE_DIR/bin/opencloud" /usr/local/bin/opencloud

    if [[ -n $RESTORE_FILE ]]; then
        start_restore
        return
    fi

    echo
    echo "Opencloud is running."
    opencloud setup-url
    echo "The APP_KEY is in $STATE_DIR/APP_KEY. Keep a copy in a safe place. Without it, the dashboard cannot read its encrypted data."
}

main "$@"

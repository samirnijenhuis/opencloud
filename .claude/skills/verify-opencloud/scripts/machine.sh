#!/usr/bin/env bash
# The disposable OrbStack machine that the verification matrix runs on.
#   machine.sh up     create the machine, or update it to this checkout, and write .verify/state/machine.env
#   machine.sh down   delete the machine and the login state; the evidence in .verify/runs stays
set -euo pipefail

SKILL=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
ROOT=$(git -C "$SKILL" rev-parse --show-toplevel)
STATE=$ROOT/.verify/state
MACHINE=${OC_VERIFY_MACHINE:-oc-verify}

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# The Mac guide makes the machine "opencloud" for the user's own apps. A verification run never touches it.
[[ $MACHINE != opencloud ]] || fail "OC_VERIFY_MACHINE=opencloud is the user's own machine. Use another name."

case ${1:-} in
up)
    mkdir -p "$STATE"
    OPENCLOUD_MACHINE=$MACHINE "$ROOT/scripts/try-on-mac.sh" | tee "$STATE/install.out"
    ip=$(sed -E -n 's#^(Setup URL|Dashboard): http://([0-9.]+):8000.*#\2#p' "$STATE/install.out" | tail -1)
    [[ -n $ip ]] || fail "The installer printed no dashboard address. Read $STATE/install.out."
    setup=$(sed -n 's/^Setup URL: //p' "$STATE/install.out" | tail -1)
    # A new install has a new database, so the owner and the cookies of an older machine are not valid there.
    if [[ -n $setup ]]; then
        rm -f "$STATE/owner.json" "$STATE/cookies.txt"
    fi
    dirty=$(git -C "$ROOT" status --porcelain --untracked-files=no | grep -q . && echo -dirty || true)
    {
        echo "MACHINE=$MACHINE"
        echo "BASE=http://$ip:8000"
        echo "SETUP_URL=$setup"
        echo "BUILT_FROM=$(git -C "$ROOT" rev-parse HEAD)$dirty"
    } >"$STATE/machine.env"
    echo "Wrote $STATE/machine.env"
    ;;
down)
    if orb list | awk '{ print $1 }' | grep -qx "$MACHINE"; then
        orb delete -f "$MACHINE"
    fi
    rm -f "$STATE/machine.env" "$STATE/owner.json" "$STATE/cookies.txt" "$STATE/install.out"
    echo "Deleted the machine $MACHINE. The evidence in $ROOT/.verify/runs stays."
    ;;
*)
    echo "Usage: $0 up | down" >&2
    exit 2
    ;;
esac

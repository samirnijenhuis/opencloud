#!/usr/bin/env bash
# Builds opencloud-<version>.tar.gz and its .sha256 file. The release workflow and the curl-install test both call it.
# Usage: build-release-archive.sh <version> <output-dir>
set -euo pipefail

version=${1:?Usage: build-release-archive.sh <version> <output-dir>}
out=${2:?Usage: build-release-archive.sh <version> <output-dir>}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

mkdir -p "$out"
out=$(cd "$out" && pwd)
name=opencloud-$version.tar.gz

# COPYFILE_DISABLE stops macOS tar from adding AppleDouble files. --no-xattrs keeps macOS attributes out, so GNU tar prints no warnings.
COPYFILE_DISABLE=1 tar --no-xattrs -C "$root" -czf "$out/$name" install.sh platform bin

cd "$out"
if command -v sha256sum >/dev/null; then
    sha256sum "$name" >"$name.sha256"
else
    shasum -a 256 "$name" >"$name.sha256"
fi
echo "$out/$name"

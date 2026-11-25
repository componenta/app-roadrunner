#!/usr/bin/env bash
set -euo pipefail
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
url=https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.15/roadrunner-2025.1.15-linux-amd64.tar.gz
curl --fail --silent --show-error --location --retry 3 "$url" -o "$work/rr.tar.gz"
echo "74589ff95e022dddf163f9316821cb80423a7db9865e96ce30a436d6898e34e7  $work/rr.tar.gz" | sha256sum --check
tar -xzf "$work/rr.tar.gz" -C "$work"
rr=$(find "$work" -type f -name rr -print -quit)
test -n "$rr"
chmod +x "$rr"
python3 tests/Integration/smoke.py "$rr"

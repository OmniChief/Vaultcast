#!/usr/bin/env bash
set -euo pipefail
export VAULTCAST_ARCH="x86-64"
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/../setup.sh" "$@"

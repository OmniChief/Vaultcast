#!/usr/bin/env bash
set -euo pipefail
export VAULTCAST_ARCH="ARM64"
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/../setup.sh" "$@"

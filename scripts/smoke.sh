#!/usr/bin/env bash
set -euo pipefail
exec node "$(dirname "$0")/smoke.mjs" "${1:?Candidate URL required}"

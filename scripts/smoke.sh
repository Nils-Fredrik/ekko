#!/usr/bin/env bash
set -euo pipefail
url=${1:?Candidate URL required}
# These routes must return success, not redirects to an unrelated endpoint.
# The API and MCP routes are intentionally strict until those work packages exist.
for path in /login /dashboard /api/v1 /mcp; do
    status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 20 "${url%/}${path}")
    if [ "$status" != 200 ]; then
        echo "Smoke test failed: $path returned $status" >&2
        exit 1
    fi
done

# Placeholder checks do not authenticate or verify the response body. Never
# promote on them; replace this with the complete smoke contract first.
echo 'Smoke contract incomplete: authenticated dashboard, API and MCP checks are required' >&2
exit 1

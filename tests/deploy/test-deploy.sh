#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cat > "$tmp/gcloud" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CALLS"
case "$*" in
  *'services describe'*)
    if [[ "${UNPINNED:-0}" == 1 ]]; then
      printf '%s\n' '{"status":{"traffic":[{"latestRevision":true,"revisionName":"ekko-web-old","percent":100}]}}'
    else
      printf '%s\n' '{"status":{"traffic":[{"revisionName":"ekko-web-old","percent":100},{"revisionName":"ekko-web-test","tag":"candidate","url":"https://candidate.invalid"}]}}'
    fi;;
  *'sql backups create'*) [[ "${FAIL_BACKUP:-0}" != 1 ]] || exit 1;;
esac
MOCK
cat > "$tmp/curl" <<'MOCK'
#!/usr/bin/env bash
if [[ "${REDIRECT_SMOKE:-0}" == 1 && "$*" == *'/dashboard'* ]]; then printf 302; else printf 200; fi
MOCK
chmod +x "$tmp/gcloud" "$tmp/curl"
mkdir "$tmp/deploy"
cp "$root/scripts/deploy.sh" "$tmp/deploy/deploy.sh"
cat > "$tmp/deploy/smoke.sh" <<'MOCK'
#!/usr/bin/env bash
[[ "${FAIL_SMOKE:-0}" != 1 ]]
MOCK
chmod +x "$tmp/deploy/deploy.sh" "$tmp/deploy/smoke.sh"
export PATH="$tmp:$PATH" CALLS="$tmp/calls" WEB_IMAGE=web JOB_IMAGE=jobs PROJECT_ID=test REGION=test REVISION_SUFFIX=test
# Even four HTTP 200 responses must not satisfy the unfinished real contract.
if "$root/scripts/smoke.sh" https://candidate.invalid; then echo 'Incomplete smoke contract accepted' >&2; exit 1; fi
if REDIRECT_SMOKE=1 "$root/scripts/smoke.sh" https://candidate.invalid; then echo 'Redirect accepted by smoke test' >&2; exit 1; fi
: > "$CALLS"
if UNPINNED=1 "$tmp/deploy/deploy.sh"; then echo 'Unpinned traffic accepted' >&2; exit 1; fi
if grep -q 'sql backups create' "$CALLS"; then echo 'Preflight ran a backup' >&2; exit 1; fi
: > "$CALLS"
if FAIL_BACKUP=1 "$tmp/deploy/deploy.sh"; then echo 'Failed backup accepted' >&2; exit 1; fi
if grep -q 'jobs update' "$CALLS"; then echo 'Migration followed failed backup' >&2; exit 1; fi
: > "$CALLS"
if FAIL_SMOKE=1 "$tmp/deploy/deploy.sh"; then echo 'Failed smoke accepted' >&2; exit 1; fi
if grep -q 'update-traffic\|jobs update ekko-worker' "$CALLS"; then echo 'Traffic or worker changed after failed smoke' >&2; exit 1; fi
: > "$CALLS"
"$tmp/deploy/deploy.sh"
python3 - "$CALLS" <<'PY'
import sys
calls = open(sys.argv[1]).read().splitlines()
def index(text):
    return next(i for i, call in enumerate(calls) if text in call)
assert index('sql backups create') < index('jobs update ekko-migrate') < index('jobs execute ekko-migrate') < index('run deploy ekko-web') < index('update-traffic') < index('jobs update ekko-worker')
PY
: > "$CALLS"
if "$root/scripts/rollback-traffic.sh" invalid; then echo 'Invalid rollback accepted' >&2; exit 1; fi
[[ ! -s "$CALLS" ]]
"$root/scripts/rollback-traffic.sh" ekko-web-old
grep -q 'update-traffic.*--to-revisions=ekko-web-old=100' "$CALLS"
echo 'Preflight, backup failure, smoke failure, promotion order and rollback checks passed (mocked).'

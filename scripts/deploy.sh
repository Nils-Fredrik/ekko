#!/usr/bin/env bash
# CI invokes this only when EKKO_DEPLOY_ENABLED is explicitly enabled after bootstrap
# and live validation. The workflow must never cancel a running main deployment.
set -euo pipefail
: "${WEB_IMAGE:?}" "${JOB_IMAGE:?}" "${PROJECT_ID:?}" "${REGION:?}" "${REVISION_SUFFIX:?}"
service=ekko-web
case "$REVISION_SUFFIX" in *[!a-z0-9-]*|''|-*|*-) echo 'Invalid revision suffix' >&2; exit 1;; esac
if ((${#REVISION_SUFFIX} > 53)); then echo 'Revision suffix too long' >&2; exit 1; fi
# Fail before backup or migration when traffic is unpinned, ambiguous or absent.
# Subsequent infrastructure changes must never expose an untested LATEST revision.
traffic=$(gcloud run services describe "$service" --region "$REGION" --project "$PROJECT_ID" --format=json)
TRAFFIC_JSON="$traffic" python3 - <<'PY'
import json, os, sys
try:
    traffic = json.loads(os.environ['TRAFFIC_JSON'])['status']['traffic']
    active = [t for t in traffic if t.get('percent', 0) > 0]
    assert active and sum(t['percent'] for t in active) == 100
    assert all(t.get('revisionName') and not t.get('latestRevision', False) for t in active)
except (ValueError, KeyError, TypeError, AssertionError):
    sys.exit('Traffic must be pinned to existing revisions before deployment')
PY
# gcloud waits for this backup operation by default. Do not use --async.
gcloud sql backups create --instance=ekko-postgres --project "$PROJECT_ID"
gcloud run jobs update ekko-migrate --image "$JOB_IMAGE" --region "$REGION" --project "$PROJECT_ID"
gcloud run jobs execute ekko-migrate --wait --region "$REGION" --project "$PROJECT_ID"
gcloud run deploy "$service" --image "$WEB_IMAGE" --region "$REGION" --project "$PROJECT_ID" --no-traffic --tag candidate --revision-suffix "$REVISION_SUFFIX" --quiet
revision="${service}-${REVISION_SUFFIX}"
traffic=$(gcloud run services describe "$service" --region "$REGION" --project "$PROJECT_ID" --format=json)
url=$(TRAFFIC_JSON="$traffic" EXPECTED_REVISION="$revision" python3 - <<'PY'
import json, os, sys
try:
    traffic = json.loads(os.environ['TRAFFIC_JSON'])['status']['traffic']
    matches = [t for t in traffic if t.get('tag') == 'candidate' and t.get('revisionName') == os.environ['EXPECTED_REVISION']]
    assert len(matches) == 1 and matches[0]['url'].startswith('https://')
    print(matches[0]['url'])
except (ValueError, KeyError, TypeError, AssertionError):
    sys.exit('Candidate revision URL missing or mismatched')
PY
)
"$(dirname "$0")/smoke.sh" "$url"
gcloud run services update-traffic "$service" --region "$REGION" --project "$PROJECT_ID" --to-revisions="${revision}=100"
for job in ekko-dispatch ekko-worker ekko-task; do
    gcloud run jobs update "$job" --image "$JOB_IMAGE" --region "$REGION" --project "$PROJECT_ID"
done

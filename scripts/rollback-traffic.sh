#!/usr/bin/env bash
set -euo pipefail
: "${PROJECT_ID:?}" "${REGION:?}"
revision=${1:?Pass the previously verified ekko-web revision}
if [[ ! "$revision" =~ ^ekko-web-[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] || ((${#revision} > 63)); then
    echo 'Invalid ekko-web revision' >&2
    exit 1
fi
gcloud run services update-traffic ekko-web --region "$REGION" --project "$PROJECT_ID" --to-revisions="${revision}=100"

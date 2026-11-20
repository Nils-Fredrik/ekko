#!/usr/bin/env bash
set -euo pipefail
: "${PROJECT_ID:?}" "${REGION:?}"
revision=${1:?Pass the previously verified ekko-web revision}
if [[ ! "$revision" =~ ^ekko-web-[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] || ((${#revision} > 63)); then
    echo 'Invalid ekko-web revision' >&2
    exit 1
fi
gcloud run services update-traffic ekko-web --region "$REGION" --project "$PROJECT_ID" --to-revisions="${revision}=100"
# Protect the image now serving traffic from Artifact Registry cleanup.
image=$(gcloud run revisions describe "$revision" --region "$REGION" --project "$PROJECT_ID" --format='value(spec.containers[0].image)')
if [[ "$image" != *@sha256:* ]]; then
    echo "Traffic moved, but the revision image is not a digest; tag it live manually: $image" >&2
    exit 1
fi
gcloud artifacts docker tags add "$image" "${image%@*}:live" --quiet

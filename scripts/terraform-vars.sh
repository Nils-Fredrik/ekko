#!/usr/bin/env bash
# Keeps one authoritative copy of the production Terraform variables in the private,
# versioned state bucket, so a plan from any worktree starts from the same values.
#
#   scripts/terraform-vars.sh pull   # download to infra/terraform/production.auto.tfvars
#   scripts/terraform-vars.sh push   # upload local changes; refused if someone pushed since your pull
#   scripts/terraform-vars.sh diff   # compare local copy with the authoritative copy
#
# The file holds no secrets (runbook: secrets live in Secret Manager only).
set -euo pipefail

OBJECT="${EKKO_TFVARS_OBJECT:-gs://ekko-514612-tfstate/ekko/production/production.auto.tfvars}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL="$ROOT/infra/terraform/production.auto.tfvars"
GENERATION="$LOCAL.generation"

remote_generation() {
    gcloud storage objects describe "$OBJECT" --format='value(generation)'
}

case "${1:-}" in
    pull)
        tmp="$(mktemp)"
        trap 'rm -f "$tmp"' EXIT
        generation="$(remote_generation)"
        gcloud storage cp --quiet "$OBJECT#$generation" "$tmp"
        if [[ -f "$LOCAL" ]] && ! cmp -s "$tmp" "$LOCAL"; then
            echo "Local copy differs from the authoritative copy; replacing it. Previous local copy: $LOCAL.local-backup" >&2
            cp "$LOCAL" "$LOCAL.local-backup"
        fi
        mv "$tmp" "$LOCAL"
        trap - EXIT
        echo "$generation" > "$GENERATION"
        echo "Pulled generation $generation to $LOCAL"
        ;;
    push)
        [[ -f "$LOCAL" && -f "$GENERATION" ]] || { echo "Run '$0 pull' first and edit the pulled file." >&2; exit 1; }
        if grep -nEi '^[[:space:]]*[a-z0-9_]*(password|secret|api_key|private_key|token)[a-z0-9_]*[[:space:]]*=' "$LOCAL"; then
            echo "Refusing to upload: the lines above look like secrets. Secrets belong in Secret Manager." >&2
            exit 1
        fi
        # Compare-and-swap: fails if another push happened after this worktree's pull.
        gcloud storage cp --quiet --if-generation-match="$(cat "$GENERATION")" "$LOCAL" "$OBJECT"
        remote_generation > "$GENERATION"
        echo "Pushed generation $(cat "$GENERATION")"
        ;;
    diff)
        tmp="$(mktemp)"
        trap 'rm -f "$tmp"' EXIT
        gcloud storage cp --quiet "$OBJECT" "$tmp"
        diff -u --label authoritative "$tmp" --label local "$LOCAL"
        ;;
    *)
        echo "Usage: $0 pull|push|diff" >&2
        exit 2
        ;;
esac

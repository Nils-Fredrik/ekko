#!/usr/bin/env bash
# Keeps one authoritative copy of the production Terraform variables in the private,
# versioned state bucket, so a plan from any worktree starts from the same values.
#
#   scripts/terraform-vars.sh pull   # download to infra/terraform/production.auto.tfvars
#   scripts/terraform-vars.sh push   # upload local changes; refused if someone pushed since your pull
#   scripts/terraform-vars.sh diff   # compare local copy with the authoritative copy
#
# The file holds no secrets (runbook: secrets live in Secret Manager only). The check in
# 'push' is a heuristic on variable names only; it cannot see a secret hidden in a value.
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
            backup="$LOCAL.$(date -u +%Y%m%dT%H%M%SZ).local-backup"
            cp "$LOCAL" "$backup"
            echo "Local copy differed from the authoritative copy and was replaced. Previous local copy: $backup" >&2
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
        # Compare-and-swap: fails if another push happened after this worktree's pull. The new
        # generation is taken from the upload's own result, never from a later lookup, so a push
        # from another worktree in between can't be recorded as ours.
        created="$(gcloud storage cp --quiet --print-created-message --if-generation-match="$(cat "$GENERATION")" "$LOCAL" "$OBJECT" 2>&1)" || {
            echo "$created" >&2
            echo "Upload refused. If the precondition failed, someone pushed after your pull: run '$0 pull' and redo your change." >&2
            exit 1
        }
        generation="$(sed -n "s|^Created: ${OBJECT}#\([0-9][0-9]*\)\$|\1|p" <<<"$created" | tail -n 1)"
        if [[ -z "$generation" ]]; then
            rm -f "$GENERATION"
            echo "Uploaded, but the new generation could not be read. Run '$0 pull' before the next push." >&2
            exit 1
        fi
        echo "$generation" > "$GENERATION"
        echo "Pushed generation $generation"
        ;;
    diff)
        [[ -f "$LOCAL" ]] || { echo "No local copy. Run '$0 pull' first." >&2; exit 1; }
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

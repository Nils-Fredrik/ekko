#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
  prepare)
    # Run only in the disposable CI checkout. Never write fixtures to a developer checkout.
    [[ "${CI:-}" == true ]] || { echo 'Fixtures require CI' >&2; exit 1; }
    for path in .env auth.json gha-creds-ekko-fixture.json storage/ekko-fixture.key public/hot bootstrap/cache/config.php storage/app/private/ekko-fixture storage/app/public/ekko-fixture storage/framework/cache/data/ekko-fixture storage/framework/sessions/ekko-fixture storage/framework/testing/ekko-fixture storage/framework/views/ekko-fixture storage/logs/ekko-fixture; do
      [[ ! -e "$path" ]] || { echo "Refusing to overwrite $path" >&2; exit 1; }
      printf '%s\n' 'local-only fixture' > "$path"
    done
    ;;
  verify)
    docker run --rm --entrypoint sh "${IMAGE:-ekko-context-check:ci}" -eu -c '
      for path in .env auth.json gha-creds-ekko-fixture.json storage/ekko-fixture.key public/hot bootstrap/cache/config.php storage/app/private/ekko-fixture storage/app/public/ekko-fixture storage/framework/cache/data/ekko-fixture storage/framework/sessions/ekko-fixture storage/framework/testing/ekko-fixture storage/framework/views/ekko-fixture storage/logs/ekko-fixture tests docs infra .github; do
        if [ -e "$path" ]; then echo "Unexpected local state: $path" >&2; exit 1; fi
      done
      for path in storage/app/private/.gitignore storage/app/public/.gitignore storage/framework/cache/data/.gitignore storage/framework/sessions/.gitignore storage/framework/views/.gitignore storage/logs/.gitignore public/build/manifest.json; do
        if [ ! -f "$path" ]; then echo "Missing runtime file: $path" >&2; exit 1; fi
      done
    '
    # Exercise the actual no-dev production handler, JSON channel and PHP settings in both targets.
    probe_log=$(mktemp)
    trap 'rm -f "$probe_log"' EXIT
    docker run --rm -i --entrypoint php -e APP_ENV=production -e LOG_CHANNEL=stderr \
      "${IMAGE:-ekko-context-check:ci}" < tests/deploy/check-logging.php > "$probe_log" 2>&1
    python3 - "$probe_log" <<'PY'
import json
import sys

with open(sys.argv[1]) as stream:
    raw = stream.read()
assert "synthetic-" not in raw, "Sensitive exception content or debug output escaped"
records = [json.loads(line) for line in raw.splitlines()]
assert [record["severity"] for record in records] == ["INFO", "ERROR"]
assert records[0]["message"] == "logging.probe"
assert records[1]["message"] == "Application exception."
assert records[1]["context"]["exception"]["type"] == "Illuminate\\Database\\QueryException"
assert all(record["context"]["tenant_id"] is None for record in records)
print("Production logging probe passed")
PY
    ;;
  *) echo 'Expected prepare or verify' >&2; exit 1;;
esac

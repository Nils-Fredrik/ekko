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
    ;;
  *) echo 'Expected prepare or verify' >&2; exit 1;;
esac

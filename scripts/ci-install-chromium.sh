#!/usr/bin/env bash
# Installs Playwright's Chromium and its system packages in CI (T10-F2).
# Apt mirrors (security.ubuntu.com) sometimes reset connections, so apt retries each
# download and the whole install is retried a bounded number of times with backoff.
# A persistent failure still fails the step with a clear message.
set -euo pipefail

attempts="${CHROMIUM_INSTALL_ATTEMPTS:-4}"
delay="${CHROMIUM_INSTALL_DELAY:-15}"

if [[ "${CI:-}" == "true" ]]; then
    sudo tee /etc/apt/apt.conf.d/80-ekko-retries >/dev/null <<'APT'
Acquire::Retries "5";
Acquire::http::Timeout "30";
Acquire::https::Timeout "30";
APT
fi

for ((i = 1; i <= attempts; i++)); do
    if npx playwright install --with-deps chromium; then
        exit 0
    fi
    if ((i == attempts)); then
        echo "::error::Chromium installation failed after ${attempts} attempts; PDF tests cannot run." >&2
        exit 1
    fi
    echo "::warning::Chromium installation attempt ${i}/${attempts} failed; retrying in ${delay}s." >&2
    sleep "$delay"
    delay=$((delay * 2))
done

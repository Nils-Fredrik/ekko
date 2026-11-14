#!/usr/bin/env bash
# Sets up Ekko for an agent session (docs/spec.md 19.6).
#
# Local worktree:   scripts/agent-setup.sh claude|cursor|codex
# Cloud session:    scripts/agent-setup.sh cloud   (Codex cloud, Cursor background agents)
#
# Installs dependencies, makes sure PostgreSQL runs, creates the agent's own databases,
# writes .env (only if missing) and runs migrations. Never copies secrets.
set -euo pipefail

cd "$(dirname "$0")/.."

agent="${1:-cloud}"
case "$agent" in
    claude) db=ekko_claude; app_port=8001; vite_port=5174 ;;
    cursor) db=ekko_cursor; app_port=8002; vite_port=5175 ;;
    codex)  db=ekko_codex;  app_port=8003; vite_port=5176 ;;
    cloud)  db=ekko;        app_port=8000; vite_port=5173 ;;
    *) echo "Usage: $0 claude|cursor|codex|cloud" >&2; exit 1 ;;
esac
test_db="${db}_testing"
session_cookie="${db}_session"

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-${DB_USERNAME:-postgres}}"

log() { printf '\n==> %s\n' "$*"; }

# PostgreSQL 16 ----------------------------------------------------------------
if ! pg_isready -q 2>/dev/null; then
    if [ "$agent" = cloud ] && command -v apt-get >/dev/null 2>&1; then
        SUDO=""
        if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; fi
        if ! command -v psql >/dev/null 2>&1; then
            log "Installing PostgreSQL"
            $SUDO apt-get update -qq
            $SUDO apt-get install -y -qq postgresql postgresql-contrib >/dev/null
        fi
        log "Starting PostgreSQL"
        $SUDO service postgresql start
        # Cloud containers only: give the postgres superuser a local password.
        $SUDO su postgres -c "psql -qc \"ALTER USER postgres PASSWORD 'postgres';\""
        export PGUSER=postgres PGPASSWORD=postgres
    else
        echo "PostgreSQL is not running on $PGHOST:$PGPORT. Start it (e.g. 'brew services start postgresql@16') and retry." >&2
        exit 1
    fi
fi

log "Creating databases $db and $test_db (if missing)"
for name in "$db" "$test_db"; do
    if ! psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$name'" | grep -q 1; then
        createdb "$name"
    fi
done

# .env -----------------------------------------------------------------------
if [ ! -f .env ]; then
    log "Writing .env for $agent"
    cp .env.example .env
    sed -i.bak \
        -e "s#^APP_PORT=.*#APP_PORT=$app_port#" \
        -e "s#^APP_URL=.*#APP_URL=http://localhost:$app_port#" \
        -e "s#^VITE_PORT=.*#VITE_PORT=$vite_port#" \
        -e "s#^DB_DATABASE=.*#DB_DATABASE=$db#" \
        -e "s#^DB_TEST_DATABASE=.*#DB_TEST_DATABASE=$test_db#" \
        -e "s#^SESSION_COOKIE=.*#SESSION_COOKIE=$session_cookie#" \
        -e "s#^DB_HOST=.*#DB_HOST=$PGHOST#" \
        -e "s#^DB_PORT=.*#DB_PORT=$PGPORT#" \
        -e "s#^DB_USERNAME=.*#DB_USERNAME=$PGUSER#" \
        -e "s#^DB_PASSWORD=.*#DB_PASSWORD=${PGPASSWORD:-}#" \
        .env
    rm -f .env.bak
else
    log ".env exists, leaving existing values unchanged"
    # Older .env files lack SESSION_COOKIE; without it, worktrees on localhost overwrite each other's session.
    if ! grep -q '^SESSION_COOKIE=' .env; then
        log "Adding SESSION_COOKIE=$session_cookie to .env"
        printf '\nSESSION_COOKIE=%s\n' "$session_cookie" >> .env
    fi
fi

# Dependencies ---------------------------------------------------------------
log "Installing Composer dependencies"
composer install --no-interaction --prefer-dist --no-progress

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --ansi
fi

log "Installing npm dependencies"
npm ci --no-audit --no-fund

log "Running migrations"
php artisan migrate --force

# Seed demo data only into an empty database, so a rerun never duplicates or overwrites data.
if [ "$(psql -d "$db" -tAc 'SELECT count(*) FROM users')" = "0" ]; then
    log "Seeding demo data"
    php artisan db:seed --force
fi

log "Building frontend"
npm run build

log "Done. Run 'composer test' and 'npm run lint' to verify."

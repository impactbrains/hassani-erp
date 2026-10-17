#!/usr/bin/env bash
# Hassani ERP - one-command start for Linux / macOS (the Windows shop computer uses "Start Hassani ERP.exe").
# Needs PHP 8.2+ (with pdo_pgsql, mbstring, intl) and PostgreSQL 14+ running. Ctrl+C stops everything.
set -euo pipefail
cd "$(dirname "$0")"
[ -f .env ] || cp .env.example .env
env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"' || true; }
PORT="$(env_get SERVER_PORT)"; PORT="${PORT:-8000}"
DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"
HOST="$(env_get SERVER_HOST)"; HOST="${HOST:-127.0.0.1}"
export PGPASSWORD="$(env_get DB_PASSWORD)"

command -v php >/dev/null || { echo "PHP 8.2+ is required."; exit 1; }
php -m | grep -qi pdo_pgsql || { echo "The PHP extension pdo_pgsql is required."; exit 1; }
if command -v psql >/dev/null; then
  if ! psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USER:-postgres}" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
    echo "Creating database ${DB_NAME} ..."
    psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USER:-postgres}" -d postgres -c "CREATE DATABASE \"${DB_NAME}\" ENCODING 'UTF8' TEMPLATE template0"
  fi
fi
[ -f vendor/autoload.php ] || composer install --no-dev --optimize-autoloader --no-interaction
[ -f public/build/manifest.json ] || { npm ci && npm run build; }
grep -qE '^APP_KEY=.+' .env || php artisan key:generate --force
php artisan migrate --force --seed
php artisan optimize:clear >/dev/null && php artisan optimize >/dev/null

mkdir -p storage/logs
php artisan queue:work --sleep=3 --tries=3 >> storage/logs/worker.log 2>&1 &
WORKER=$!
php artisan schedule:work >> storage/logs/scheduler.log 2>&1 &
SCHED=$!
trap 'kill $WORKER $SCHED 2>/dev/null; echo; echo "Hassani ERP stopped."' EXIT
echo "Hassani ERP: http://localhost:${PORT}  (Ctrl+C to stop)"
( sleep 3; (command -v xdg-open >/dev/null && xdg-open "http://localhost:${PORT}") || (command -v open >/dev/null && open "http://localhost:${PORT}") || true ) >/dev/null 2>&1 &
php artisan serve --host="$HOST" --port="$PORT" --no-reload

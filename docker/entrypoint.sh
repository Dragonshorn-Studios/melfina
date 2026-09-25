#!/bin/sh
set -eu

cd /app

# Runtime dirs the .dockerignore keeps out of the image.
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

# Persistent state lives in the /data volume: .env (incl. APP_KEY) and the
# SQLite database. On first boot both are seeded, so containers are disposable.
mkdir -p /data

if [ ! -f /data/.env ]; then
    cp .env.example /data/.env
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" /data/.env

    # .env.example ships dev defaults; a Docker-only deploy (no compose env)
    # would otherwise run with debug on and logs hidden in storage/logs.
    # Real env vars always win over .env, so compose/deploy env still overrides.
    sed -i "s|^APP_ENV=.*|APP_ENV=${APP_ENV:-production}|" /data/.env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=${APP_DEBUG:-false}|" /data/.env
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL:-http://localhost:8000}|" /data/.env
    sed -i "s|^LOG_CHANNEL=.*|LOG_CHANNEL=${LOG_CHANNEL:-stderr}|" /data/.env
fi

# The seeded .env.example leaves DB_DATABASE unset, which points Laravel at
# /app/database/database.sqlite — a non-persistent in-image path. Pin it to the
# volume so Docker-only deploys (no compose env) work the same as compose.
if ! grep -q '^DB_DATABASE=' /data/.env; then
    printf '\nDB_DATABASE=%s\n' "${DB_DATABASE:-/data/database.sqlite}" >> /data/.env
fi

# Same for the Passport key dir — the app reads keys through
# Passport::loadKeysFrom(env('PASSPORT_KEYS_DIR')), so it must be in .env.
if ! grep -q '^PASSPORT_KEYS_DIR=' /data/.env; then
    printf '\nPASSPORT_KEYS_DIR=%s\n' "${PASSPORT_KEYS_DIR:-/data/passport}" >> /data/.env
fi

ln -sfn /data/.env .env

DB_PATH="${DB_DATABASE:-/data/database.sqlite}"
mkdir -p "$(dirname "$DB_PATH")"
touch "$DB_PATH"

# OAuth signing keys live on the volume too — losing them invalidates every
# issued MCP token. PASSPORT_KEYS_DIR makes loadKeysFrom read them here.
KEYS_DIR="${PASSPORT_KEYS_DIR:-/data/passport}"
mkdir -p "$KEYS_DIR"
if [ ! -f "$KEYS_DIR/oauth-private.key" ]; then
    PASSPORT_KEYS_DIR="$KEYS_DIR" php artisan passport:keys --force
fi

php artisan migrate --force --graceful

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

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
fi

ln -sfn /data/.env .env

DB_PATH="${DB_DATABASE:-/data/database.sqlite}"
mkdir -p "$(dirname "$DB_PATH")"
touch "$DB_PATH"

php artisan migrate --force --graceful

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

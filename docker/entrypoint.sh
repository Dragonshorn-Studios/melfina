#!/bin/sh
set -eu

cd /app

if [ ! -f .env ]; then
    cp .env.example .env
fi

composer install --no-interaction --prefer-dist

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

mkdir -p database
touch database/database.sqlite
php artisan migrate --force --graceful

if [ ! -f public/build/manifest.json ]; then
    npm install --ignore-scripts
    npm run build
fi

exec php artisan serve --host=0.0.0.0 --port=8000

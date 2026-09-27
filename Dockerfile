FROM dunglas/frankenphp:php8.4-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libsqlite3-dev \
        xz-utils \
        curl \
    && install-php-extensions pdo_sqlite zip bcmath opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && curl -fsSL https://nodejs.org/dist/v22.20.0/node-v22.20.0-linux-x64.tar.xz \
        | tar -xJ -C /usr/local --strip-components=1 \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

# Binary VFS uploads are capped at 10 MB app-side; php.ini-production's
# 2M/8M defaults would reject the multipart bodies before the app sees them.
RUN cp docker/php/uploads.ini "$PHP_INI_DIR/conf.d/uploads.ini"

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci --ignore-scripts \
    && npm run build \
    && rm -rf node_modules

COPY docker/entrypoint.sh /usr/local/bin/melfina-entrypoint
RUN chmod +x /usr/local/bin/melfina-entrypoint

EXPOSE 8000

ENTRYPOINT ["melfina-entrypoint"]

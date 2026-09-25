FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libsqlite3-dev \
        xz-utils \
        curl \
    && docker-php-ext-install pdo_sqlite zip bcmath \
    && curl -fsSL https://nodejs.org/dist/v22.20.0/node-v22.20.0-linux-x64.tar.xz \
        | tar -xJ -C /usr/local --strip-components=1 \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci --ignore-scripts \
    && npm run build \
    && rm -rf node_modules

COPY docker/entrypoint.sh /usr/local/bin/melfina-entrypoint
RUN chmod +x /usr/local/bin/melfina-entrypoint

EXPOSE 8000

ENTRYPOINT ["melfina-entrypoint"]

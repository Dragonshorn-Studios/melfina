FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libsqlite3-dev \
        nodejs \
        npm \
    && docker-php-ext-install pdo_sqlite zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/melfina-entrypoint
RUN chmod +x /usr/local/bin/melfina-entrypoint

EXPOSE 8000

ENTRYPOINT ["melfina-entrypoint"]

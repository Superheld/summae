FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install bcmath pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-memory.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

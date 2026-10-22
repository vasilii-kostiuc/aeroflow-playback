# Production image: code and prod-only dependencies baked in. Playback has no
# HTTP surface — the image runs Messenger workers (the deploy compose file sets
# which transports each one consumes) and the migrations.
#
# Patch version pinned: 8.5.12 fixes CVE-2026-109618/109619 (unauthenticated
# RCE in PHP request handling). Raise it on every PHP security release.
FROM php:8.5.12-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        librabbitmq-dev \
        unzip \
    # pcntl lets `messenger:consume` stop on SIGTERM between messages instead
    # of being killed in the middle of one when the container is replaced.
    && docker-php-ext-install pdo_pgsql intl zip pcntl \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /var/www

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var

USER www-data

CMD ["php", "bin/console", "messenger:consume", "announcement_playback", "agent_events", "scheduler", "--time-limit=3600", "--memory-limit=128M"]

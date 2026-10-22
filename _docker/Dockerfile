FROM php:8.5.12-fpm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    librabbitmq-dev \
    && docker-php-ext-install pdo_pgsql intl zip \
    && pecl install amqp \
    && docker-php-ext-enable amqp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

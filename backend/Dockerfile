# PHP 8.4-FPM image for Laravel 13 (dev). Code/vendor are bind-mounted at runtime.
FROM php:8.4-fpm

# System libraries required to build the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions for this stack: Postgres, Redis, queues, and Laravel essentials.
# mbstring and opcache are already bundled/enabled in the base image, so they are
# intentionally omitted (installing a bundled ext yields an empty modules/ dir and fails).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        gd \
        bcmath \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer (for dependency management inside the container).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Default: serve via PHP-FPM on 9000 (reached only by nginx/queue over the compose network).
CMD ["php-fpm"]

# Lightweight PHP CLI image for running tests with code coverage
FROM php:8.2-cli

# Install only what's needed for tests + mPDF dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libonig-dev \
    libzip-dev \
    zip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by mPDF and the application
RUN docker-php-ext-install \
    mbstring \
    gd \
    zip \
    bcmath

# Xdebug for code coverage
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=coverage" > /usr/local/etc/php/conf.d/xdebug.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies (cached in named volume, only re-runs when composer.json changes)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Source is mounted as volume at runtime - no COPY needed

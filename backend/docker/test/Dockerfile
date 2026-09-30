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
    bcmath \
    mysqli

# Xdebug for code coverage
# (pinned + retried: unpinned pecl install fails on PHP 8.2, and pecl.php.net is intermittently unreliable)
RUN for i in 1 2 3 4 5; do pecl install xdebug-3.5.0 && break || sleep 10; done \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=coverage" > /usr/local/etc/php/conf.d/xdebug.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies (cached in named volume, only re-runs when composer.json changes)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Source is mounted as volume at runtime - no COPY needed

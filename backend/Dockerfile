# Backend Dockerfile
# PHP 8.2 with Apache, MySQL extensions, and Composer

FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mysqli \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

# Install Xdebug (for code coverage)
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configure Xdebug for coverage only (not debugging)
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure Apache
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy Apache config
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy PHP config
COPY docker/php/php.ini /usr/local/etc/php/php.ini

# Copy application files
COPY . /var/www/html

# Install Composer dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Create directories with proper permissions
RUN mkdir -p uploads cache logs \
    && chown -R www-data:www-data uploads cache logs \
    && chmod -R 755 uploads cache logs

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port
EXPOSE 80

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Start Apache
CMD ["apache2-foreground"]

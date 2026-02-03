# Frontend Dockerfile
# PHP 8.2 with Apache for SurveyJS forms

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
    zip

# Install Composer (if needed for dependencies)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure Apache
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Create default Apache config if custom one doesn't exist
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Create default PHP config
RUN printf "display_errors = On\n\
error_reporting = E_ALL\n\
upload_max_filesize = 10M\n\
post_max_size = 12M\n\
memory_limit = 256M\n\
date.timezone = Europe/Berlin\n" > /usr/local/etc/php/php.ini

# Copy application files
COPY . /var/www/html

# Create .env if it doesn't exist
RUN if [ ! -f .env ]; then \
        cp .env.example .env 2>/dev/null || \
        echo 'BACKEND_API_URL=http://backend/api\n\
FROM_EMAIL=noreply@example.com\n\
ALLOWED_ORIGINS=http://localhost:8081' > .env; \
    fi

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]

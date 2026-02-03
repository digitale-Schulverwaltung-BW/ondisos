#!/bin/bash
set -e

echo "🚀 Starting Backend Entrypoint..."

# MySQL healthcheck is handled by docker-compose
# Skip manual wait as depends_on already ensures MySQL is healthy
echo "⏳ MySQL should be ready (checked by docker-compose healthcheck)"

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cat > .env <<EOF
# Application
APP_ENV=${APP_ENV:-production}
APP_DEBUG=${APP_DEBUG:-false}

# Database
DB_HOST=${DB_HOST:-mysql}
DB_PORT=${DB_PORT:-3306}
DB_NAME=${DB_NAME:-anmeldung}
DB_USER=${DB_USER:-anmeldung}
DB_PASS=${DB_PASS:-secret}

# Auto-Expunge
AUTO_EXPUNGE_DAYS=${AUTO_EXPUNGE_DAYS:-90}
AUTO_MARK_AS_READ=${AUTO_MARK_AS_READ:-true}

# Session
SESSION_LIFETIME=${SESSION_LIFETIME:-3600}
SESSION_SECURE=${SESSION_SECURE:-false}

# Authentication (Optional)
AUTH_ENABLED=${AUTH_ENABLED:-false}

# PDF Tokens
PDF_TOKEN_SECRET=${PDF_TOKEN_SECRET:-change-me-min-32-characters}

# File Upload
UPLOAD_MAX_SIZE=${UPLOAD_MAX_SIZE:-10485760}
UPLOAD_ALLOWED_TYPES=${UPLOAD_ALLOWED_TYPES:-pdf,jpg,jpeg,png,gif,doc,docx}
EOF
    echo "✅ .env file created!"
fi

# Install/update Composer dependencies
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo "✅ Composer dependencies installed!"
fi

# Create required directories
echo "📁 Creating required directories..."
mkdir -p uploads cache logs
chown -R www-data:www-data uploads cache logs
chmod -R 755 uploads cache logs
echo "✅ Directories created!"

# Set permissions
echo "🔐 Setting permissions..."
chown -R www-data:www-data /var/www/html
echo "✅ Permissions set!"

echo "✅ Backend setup complete!"
echo "🌐 Backend available at: http://localhost:8080"
echo "📊 Admin interface: http://localhost:8080/index.php"
echo ""

# Execute CMD (apache2-foreground)
exec "$@"

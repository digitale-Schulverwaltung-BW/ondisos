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

# API Secret
API_SECRET_KEY=${API_SECRET_KEY:-dev-api-key-replace-in-production}

# Multi-Tenant Mode
MULTI_TENANT_ENABLED=${MULTI_TENANT_ENABLED:-false}

# Platform Admin (required for multi-tenant mode)
ADMIN_USERNAME=${ADMIN_USERNAME:-}
ADMIN_PASSWORD_HASH=${ADMIN_PASSWORD_HASH:-}

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

# Run database migration (idempotent — safe to run on every start)
# The v3 schema (tenants, form_configs, anmeldungen.tenant_id) is required in BOTH
# single- and multi-tenant mode: all repositories read and write tenant_id. Upgrades
# from v2.x therefore must migrate regardless of MULTI_TENANT_ENABLED.
# Must run after composer install so vendor/autoload.php is available.
echo "🏢 Running database migration..."
php /var/www/html/migrate.php
echo "✅ Migration complete!"

# Create required directories
echo "📁 Creating required directories..."
mkdir -p uploads cache logs
echo "✅ Directories created!"

# Set permissions (only for writable directories, not entire volume)
echo "🔐 Setting permissions..."
chown -R www-data:www-data uploads cache logs
chmod -R 755 uploads cache logs
echo "✅ Permissions set!"

echo "✅ Backend setup complete!"
echo "🌐 Backend available at: http://localhost:9080"
echo "📊 Admin interface: http://localhost:9080/index.php"
echo ""

# Execute CMD (apache2-foreground)
exec "$@"

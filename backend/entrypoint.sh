#!/bin/sh
set -e

cd /var/www/html

# Install dependencies if vendor is missing (volume mount may overwrite build artifacts)
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — running composer install..."
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Ensure storage directories exist with correct permissions
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Generate APP_KEY on first boot when the mounted .env still has an empty key.
if [ -z "$APP_KEY" ] && [ -f .env ]; then
    php artisan key:generate --force
    APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)"
    export APP_KEY
fi

# Publish/update Filament assets when the package is installed.
if php artisan list --raw | grep -q '^filament:assets'; then
    php artisan filament:assets
fi

# Cache config for production performance
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

# Run pending migrations (safe with --force, idempotent)
php artisan migrate --force 2>/dev/null || echo "[entrypoint] Migration skipped (DB not ready yet)"

# Create or update the first admin account when credentials are provided.
if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php artisan db:seed --class=AdminUserSeeder --force
fi

exec "$@"

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

# Cache config for production performance
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

# Run pending migrations (safe with --force, idempotent)
php artisan migrate --force 2>/dev/null || echo "[entrypoint] Migration skipped (DB not ready yet)"

exec "$@"

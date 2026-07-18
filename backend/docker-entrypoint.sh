#!/bin/sh
set -e

composer install --no-dev --no-interaction --optimize-autoloader

chown -R www-data:www-data /var/www/storage /var/www/bootstrap /var/www/public
chmod -R ug+rwX /var/www/storage /var/www/bootstrap /var/www/public

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:CHANGE_ME_GENERATE_WITH_32_CHAR_STRING" ]; then
    APP_KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));")
    export APP_KEY
    echo "APP_KEY=$APP_KEY" > /var/www/.env
fi

php artisan migrate --force 2>/dev/null || true

mkdir -p /var/www/storage/app/avatars
chown -R www-data:www-data /var/www/storage/app/avatars
chmod -R ug+rwX /var/www/storage/app/avatars

chown -R www-data:www-data /var/www/storage
chmod -R ug+rwX /var/www/storage

exec "$@"

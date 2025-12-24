#!/bin/bash
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
while ! php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "MySQL is ready!"

# Run migrations
echo "Running migrations..."
php /var/www/html/bin/migrate.php

# Seed database if needed
if [ "${APP_ENV}" = "development" ] && [ ! -f /var/www/html/storage/.seeded ]; then
    echo "Seeding database..."
    php /var/www/html/bin/seed.php
    touch /var/www/html/storage/.seeded
fi

# Set permissions
chown -R www-data:www-data /var/www/html/storage

echo "Starting Chap server..."
exec "$@"

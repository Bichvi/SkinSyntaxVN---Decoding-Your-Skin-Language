#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "[php-backend] vendor/ missing — running composer install..."
  composer update --no-dev --optimize-autoloader --no-interaction
fi

exec php-fpm -F

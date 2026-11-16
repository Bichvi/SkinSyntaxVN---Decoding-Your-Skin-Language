#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "[php-backend] vendor/ missing — running composer install..."
  composer update --no-dev --optimize-autoloader --no-interaction
fi

# The uploads named volume is created root-owned, but php-fpm runs as www-data:
# make sure the upload folders exist and are writable (live recordings, products, reviews).
UPLOADS=/var/www/html/public/uploads
mkdir -p "$UPLOADS/live_recordings/tmp" "$UPLOADS/products" "$UPLOADS/reviews"
chown -R www-data:www-data "$UPLOADS" 2>/dev/null || echo "[php-backend] warning: could not chown $UPLOADS"

# PHP login sessions live on the php_sessions named volume (docker/php/zz-skinsyntax-session.ini),
# so restarting or rebuilding this container no longer logs everybody out.
SESSIONS=/var/lib/php/sessions
mkdir -p "$SESSIONS"
chown www-data:www-data "$SESSIONS" && chmod 700 "$SESSIONS" || echo "[php-backend] warning: could not prepare $SESSIONS"

exec php-fpm -F

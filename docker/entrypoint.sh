#!/usr/bin/env bash
set -euo pipefail

mkdir -p storage/app/public storage/app/nbx/tmp storage/app/nbx/work storage/app/nbx/output storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan config:cache --no-ansi || true
php artisan route:cache --no-ansi || true
php artisan view:cache --no-ansi || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-ansi
fi

exec "$@"

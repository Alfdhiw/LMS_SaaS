#!/bin/sh
set -eu
cd /var/www/html

if [ ! -f .env ]; then
  echo 'Missing .env. Copy .env.m0.example to .env first.' >&2
  exit 1
fi

if [ ! -f artisan ]; then
  echo '[M0] Downloading official Laravel 13 project skeleton ...'
  tmp=.m0-laravel-scaffold
  if [ -e "$tmp" ]; then
    echo "Remove incomplete directory $tmp and retry." >&2
    exit 1
  fi
  composer create-project 'laravel/laravel:^13.0' "$tmp" \
    --prefer-dist --no-interaction --no-install --no-scripts --no-progress

  # Existing M0 overlay files (AGENTS/docs/routes/tests/env/compose) take precedence.
  cp -a -n "$tmp"/. ./
  rm -rf "$tmp"

  # Vanilla Laravel migrations are not our approved ERD v1.1.
  # M0 creates no tenant/user schema; schema is implemented after M1 approval.
  find database/migrations -maxdepth 1 -type f -name '*.php' -delete
  touch database/migrations/.gitkeep
fi

if [ ! -d vendor ]; then
  echo '[M0] Installing Composer dependencies ...'
  composer install --prefer-dist --no-interaction --no-progress
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --no-interaction
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache || true

php artisan --version
php artisan config:clear
printf '[M0] Laravel base ready. No tenant tables were created.\n'

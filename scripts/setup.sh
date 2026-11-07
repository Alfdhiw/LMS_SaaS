#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v docker >/dev/null || { echo 'Install Docker first' >&2; exit 1; }
docker info >/dev/null
[ -f .env ] || cp .env.m0.example .env

docker compose config --quiet
docker compose build app
docker compose up -d db redis mailpit
docker compose run --rm --no-deps --entrypoint sh app scripts/bootstrap-laravel.sh
docker compose up -d app web worker scheduler
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan test --filter=M0
printf '\n[M0] Open http://localhost:8080 and Mailpit http://localhost:8025\n'

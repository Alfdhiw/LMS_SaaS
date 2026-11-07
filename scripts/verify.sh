#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose ps
docker compose exec -T app php artisan --version
docker compose exec -T app php artisan test --filter=M0
docker compose exec -T app ./vendor/bin/pint --test

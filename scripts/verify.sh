#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose ps
docker compose exec -T app php artisan --version
if grep -qE '^DB_USERNAME=lms_runtime[[:space:]]*$' .env; then
  sh scripts/m1-operator.sh test-m0
else
  docker compose exec -T app sh scripts/m1-test-runtime.sh --filter M0
fi
docker compose exec -T app ./vendor/bin/pint --test

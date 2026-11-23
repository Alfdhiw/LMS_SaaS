#!/usr/bin/env sh
set -eu
set +x
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"
owner_secret=$(CDPATH= cd -- "$project_root/.." && pwd)/.lms-saas-secrets/postgres_owner_password
case "${1:-}" in migrate|seed|test|test-m0) operation=$1 ;; *) echo 'Allowed: migrate, seed, test, test-m0.' >&2; exit 1 ;; esac
[ -f "$owner_secret" ] || { echo 'Local owner secret is missing.' >&2; exit 1; }
[ -f docker-compose.override.yml ] || { echo 'Local role override is not active.' >&2; exit 1; }
docker compose run --rm --no-deps --volume "$owner_secret:/run/secrets/postgres_owner_password:ro" \
    --entrypoint sh app scripts/m1-owner-exec.sh "$operation"
if [ "$operation" = migrate ]; then
    docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas < database/security/m1_audit_runtime_grants.sql
fi

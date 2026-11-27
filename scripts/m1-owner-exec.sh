#!/bin/sh
# Only for an isolated, short-lived operator container; never a runtime service.
set -eu
set +x
umask 077

[ "${APP_ENV:-}" = local ] || { echo 'Owner operation is restricted to local.' >&2; exit 1; }
[ "${DB_HOST:-}" = db ] && [ "${DB_DATABASE:-}" = lms_saas ] || {
    echo 'Refusing unexpected database target.' >&2; exit 1;
}
[ -z "${DB_URL:-}" ] || { echo 'DB_URL must be empty for owner operations.' >&2; exit 1; }
[ -s /run/secrets/postgres_owner_password ] || { echo 'Owner secret is unavailable.' >&2; exit 1; }
DB_USERNAME=lms_user
DB_PASSWORD=$(tr -d '\r\n' < /run/secrets/postgres_owner_password)
export DB_USERNAME DB_PASSWORD

case "${1:-}" in
    migrate) exec php artisan migrate --force ;;
    seed) exec php artisan db:seed --force ;;
    test)
        if [ "$#" -eq 1 ]; then exec sh scripts/m1-test-runtime.sh; fi
        [ "$#" -eq 3 ] && [ "$2" = --filter ] || { echo 'Only an optional test filter is allowed.' >&2; exit 1; }
        exec sh scripts/m1-test-runtime.sh --filter "$3"
        ;;
    test-m0) exec sh scripts/m1-test-runtime.sh --filter M0 ;;
    *) echo 'Allowed operations: migrate, seed, test, test-m0.' >&2; exit 1 ;;
esac

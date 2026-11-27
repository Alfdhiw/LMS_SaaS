#!/bin/sh
# Isolate Artisan/PHPUnit output from the bind-mounted web runtime, before Laravel boots.
set -eu
set +x
umask 077

if [ "$#" -ne 0 ]; then
    [ "$#" -eq 2 ] && [ "$1" = --filter ] && [ -n "$2" ] || {
        echo 'Only an optional --filter value is allowed.' >&2
        exit 2
    }
fi

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"
test_runtime_directory=$(mktemp -d /tmp/lms-saas-test-runtime.XXXXXXXXXX)

cleanup_test_runtime() {
    # Never remove a caller-provided path, a symlink or anything outside this fresh directory.
    case "$test_runtime_directory" in
        /tmp/lms-saas-test-runtime.??????????) ;;
        *) echo 'Refusing unexpected test runtime cleanup target.' >&2; return 1 ;;
    esac
    [ -d "$test_runtime_directory" ] && [ ! -L "$test_runtime_directory" ] \
        && [ "$(readlink -f -- "$test_runtime_directory")" = "$test_runtime_directory" ] || {
        echo 'Refusing unresolved test runtime cleanup target.' >&2
        return 1
    }
    rm -rf -- "$test_runtime_directory"
}
trap cleanup_test_runtime EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$test_runtime_directory/storage/framework/cache/data" \
    "$test_runtime_directory/storage/framework/cache/locks" \
    "$test_runtime_directory/storage/framework/sessions" \
    "$test_runtime_directory/storage/framework/views" \
    "$test_runtime_directory/storage/logs" \
    "$test_runtime_directory/storage/app/private" \
    "$test_runtime_directory/storage/app/public" \
    "$test_runtime_directory/bootstrap/cache"

# The parent Artisan process and concurrency children must use the same isolated paths.
export APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=lms_saas_test DB_URL=
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export LARAVEL_STORAGE_PATH="$test_runtime_directory/storage"
export VIEW_COMPILED_PATH="$test_runtime_directory/storage/framework/views"
export APP_CONFIG_CACHE="$test_runtime_directory/bootstrap/cache/config.php"
export APP_SERVICES_CACHE="$test_runtime_directory/bootstrap/cache/services.php"
export APP_PACKAGES_CACHE="$test_runtime_directory/bootstrap/cache/packages.php"
export APP_EVENTS_CACHE="$test_runtime_directory/bootstrap/cache/events.php"
export APP_ROUTES_CACHE="$test_runtime_directory/bootstrap/cache/routes-v7.php"

# Do not exec: EXIT must clean up after both a successful and a failed test command.
php artisan test "$@"

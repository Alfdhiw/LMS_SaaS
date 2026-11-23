#!/bin/sh
# Executed only inside the local PostgreSQL container. Password stays out of argv.
set -eu
set +x

secret=/run/secrets/postgres_owner_password
[ -s "$secret" ] || { echo 'Owner secret is unavailable.' >&2; exit 1; }
PGPASSWORD=$(tr -d '\r\n' < "$secret")
[ -n "$PGPASSWORD" ] || { echo 'Owner secret is empty.' >&2; exit 1; }
export PGPASSWORD

case "${1:-}" in
    private)
        [ "$#" -eq 2 ] || { echo 'Expected a private database name.' >&2; exit 1; }
        case "$2" in lms_restore_*|lms_aux_*) ;; *) echo 'Invalid private database name.' >&2; exit 1 ;; esac
        case "$2" in *[!a-z0-9_]*) echo 'Invalid private database name.' >&2; exit 1 ;; esac
        exec psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d postgres -v "target_db=$2"
        ;;
    grants)
        [ "$#" -eq 1 ] || exit 1
        exec psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d lms_saas
        ;;
    test)
        [ "$#" -eq 1 ] || exit 1
        exec psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d lms_saas_test
        ;;
    backup|restore)
        operation=$1
        [ "$#" -ge 2 ] || { echo 'Expected a backup filename.' >&2; exit 1; }
        backup_name=$2
        case "$backup_name" in lms_backup_*.dump) ;; *) echo 'Invalid backup filename.' >&2; exit 1 ;; esac
        case "$backup_name" in *[!a-z0-9_.]*|*..*) echo 'Invalid backup filename.' >&2; exit 1 ;; esac
        [ "${#backup_name}" -le 70 ] || { echo 'Backup filename is too long.' >&2; exit 1; }
        backup_path=/tmp/$backup_name
        if [ "$operation" = backup ]; then
            [ "$#" -eq 2 ] || exit 1
            umask 077
            set -C
            pg_dump -w -Fc -U lms_user -d lms_saas > "$backup_path"
            echo "Backup created: $backup_path"
            exit 0
        fi
        [ "$#" -eq 3 ] || { echo 'Expected a private restore database.' >&2; exit 1; }
        target=$3
        case "$target" in lms_restore_*|lms_aux_*) ;; *) echo 'Invalid restore target.' >&2; exit 1 ;; esac
        case "$target" in *[!a-z0-9_]*) echo 'Invalid restore target.' >&2; exit 1 ;; esac
        [ "${#target}" -le 63 ] || { echo 'Invalid restore target.' >&2; exit 1; }
        [ -f "$backup_path" ] && [ ! -L "$backup_path" ] || { echo 'Backup file is missing or a symlink.' >&2; exit 1; }
        # Do not restore into a database with user schemas, relations, routines, or types.
        safe=$(psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d "$target" -tAc "SELECT (current_user = 'lms_user' AND (SELECT pg_get_userbyid(datdba) = 'lms_user' FROM pg_database WHERE datname = current_database()) AND NOT has_database_privilege('lms_runtime', current_database(), 'CONNECT') AND NOT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname NOT IN ('public', 'information_schema') AND nspname NOT LIKE 'pg\\_%' ESCAPE '\\') AND NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public') AND NOT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public') AND NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public'))::int")
        [ "$safe" = 1 ] || { echo 'Restore target is not empty, private, and owner-controlled.' >&2; exit 1; }
        exec pg_restore -w --exit-on-error --no-owner --no-acl -U lms_user -d "$target" "$backup_path"
        ;;
    *) echo 'Unsupported owner operation.' >&2; exit 1 ;;
esac

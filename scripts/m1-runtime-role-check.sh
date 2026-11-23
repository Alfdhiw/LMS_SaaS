#!/bin/sh
# Read-only preflight in a short-lived client; the DB override is not yet active.
set -eu
set +x
[ -s /run/secrets/postgres_owner_password ] || { echo 'Owner secret is unavailable.' >&2; exit 1; }
PGPASSWORD=$(tr -d '\r\n' < /run/secrets/postgres_owner_password)
[ -n "$PGPASSWORD" ] || { echo 'Owner secret is empty.' >&2; exit 1; }
export PGPASSWORD
exec psql -X -w -v ON_ERROR_STOP=1 -h db -U lms_user -d lms_saas -tAc "SELECT count(*) FROM pg_roles WHERE rolname='lms_runtime'"

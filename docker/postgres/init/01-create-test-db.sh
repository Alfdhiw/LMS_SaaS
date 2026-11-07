#!/bin/sh
set -eu
: "${POSTGRES_TEST_DB:=lms_saas_test}"
case "$POSTGRES_TEST_DB" in
  *[!a-zA-Z0-9_]*|'') echo 'Unsafe POSTGRES_TEST_DB' >&2; exit 1 ;;
esac
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -c "CREATE DATABASE \"${POSTGRES_TEST_DB}\";"

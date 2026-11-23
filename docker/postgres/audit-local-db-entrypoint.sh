#!/bin/sh
# Only the PostgreSQL service receives the owner secret; accept Windows CRLF.
set -eu
set +x
[ -s /run/secrets/postgres_owner_password ] || { echo 'Owner database secret is missing.' >&2; exit 1; }
POSTGRES_PASSWORD=$(tr -d '\r\n' < /run/secrets/postgres_owner_password)
[ -n "$POSTGRES_PASSWORD" ] || { echo 'Owner database secret is empty.' >&2; exit 1; }
export POSTGRES_PASSWORD
# Compose discards the image CMD when an override supplies an entrypoint.
# Preserve PostgreSQL's default command when none was provided explicitly.
if [ "$#" -eq 0 ]; then
    set -- postgres
fi
exec /usr/local/bin/docker-entrypoint.sh "$@"

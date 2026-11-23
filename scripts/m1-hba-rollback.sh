#!/bin/sh
# Recovery inside the verified PostgreSQL container, as OS user postgres.
# Uses pg_ctl, so recovery does not depend on a successful database login.
set -eu
set +x
[ "$#" -eq 3 ] || { echo 'Expected staged backup, original SHA-256, and current SHA-256.' >&2; exit 1; }
backup=$1
original_hash=$2
current_hash=$3
data=/var/lib/postgresql/data
target=$data/pg_hba.conf
[ "$(id -un)" = postgres ] || { echo 'Run recovery as OS user postgres.' >&2; exit 1; }
case "$backup" in /tmp/lms_hba_*.conf) ;; *) echo 'Unexpected backup path.' >&2; exit 1 ;; esac
case "$backup" in *..*|*[!a-z0-9_./]*) echo 'Unexpected backup path.' >&2; exit 1 ;; esac
for expected_hash in "$original_hash" "$current_hash"; do
    [ "${#expected_hash}" -eq 64 ] || { echo 'Invalid SHA-256.' >&2; exit 1; }
    case "$expected_hash" in *[!a-f0-9]*) echo 'Invalid SHA-256.' >&2; exit 1 ;; esac
done
[ -f "$backup" ] && [ ! -L "$backup" ] && [ -f "$target" ] && [ ! -L "$target" ] || {
    echo 'Expected regular backup and HBA files.' >&2; exit 1;
}
[ "$(stat -c '%a:%U:%G' "$backup")" = '600:postgres:postgres' ] || {
    echo 'Staged backup must be owned by postgres:postgres with mode 600.' >&2; exit 1;
}
hash_file() { sha256sum "$1" | cut -d ' ' -f 1; }
[ "$(hash_file "$backup")" = "$original_hash" ] || { echo 'Backup checksum mismatch.' >&2; exit 1; }
[ "$(hash_file "$target")" = "$current_hash" ] || { echo 'Current HBA changed; review before recovery.' >&2; exit 1; }
temporary=$(mktemp "$data/.pg_hba.rollback.XXXXXX")
cleanup() { if [ -n "$temporary" ]; then rm -f -- "$temporary"; fi; }
trap cleanup EXIT
cp "$backup" "$temporary"
chmod 600 "$temporary"
[ "$(hash_file "$temporary")" = "$original_hash" ] || { echo 'Recovery copy checksum mismatch.' >&2; exit 1; }
[ "$(hash_file "$target")" = "$current_hash" ] || { echo 'Current HBA changed during recovery.' >&2; exit 1; }
mv -f "$temporary" "$target"
temporary=''
pg_ctl -D "$data" reload
echo 'Original HBA restored; reload requested. Verify new connections and server logs.'

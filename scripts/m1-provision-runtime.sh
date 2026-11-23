#!/bin/sh
# Runs only in a disposable PostgreSQL client container with read-only secrets.
set -eu
set +x
umask 077

for secret_file in /run/secrets/postgres_owner_password /run/secrets/postgres_runtime_password; do
    [ -s "$secret_file" ] || { echo 'Missing database secret.' >&2; exit 1; }
done
PGPASSWORD=$(tr -d '\r\n' < /run/secrets/postgres_owner_password)
AUDIT_RUNTIME_PASSWORD=$(tr -d '\r\n' < /run/secrets/postgres_runtime_password)
export PGPASSWORD AUDIT_RUNTIME_PASSWORD

# The target database and owner are guarded here and in the grants script.
# Suppress statement logging for the password-bearing CREATE ROLE operation.
psql -X -v ON_ERROR_STOP=1 -h db -U lms_user -d lms_saas <<'SQL'
BEGIN;
SET LOCAL log_statement = 'none';
SET LOCAL log_min_error_statement = 'panic';
DO $guard$
BEGIN
    IF current_database() <> 'lms_saas' OR current_user <> 'lms_user' THEN
        RAISE EXCEPTION 'Unexpected database or owner';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_runtime') THEN
        RAISE EXCEPTION 'Runtime role already exists; refusing to replace it';
    END IF;
END
$guard$;
\getenv runtime_password AUDIT_RUNTIME_PASSWORD
SELECT format('CREATE ROLE lms_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD %L', :'runtime_password') \gexec
COMMIT;
SQL

psql -X -v ON_ERROR_STOP=1 -h db -U lms_user -d lms_saas -f /run/m1_audit_runtime_grants.sql

PGPASSWORD=$AUDIT_RUNTIME_PASSWORD
unset AUDIT_RUNTIME_PASSWORD
export PGPASSWORD
psql -X -v ON_ERROR_STOP=1 -h db -U lms_runtime -d lms_saas <<'SQL'
DO $verify$
DECLARE
    audit_table text;
    forbidden_privilege text;
BEGIN
    IF current_database() <> 'lms_saas' OR current_user <> 'lms_runtime' THEN
        RAISE EXCEPTION 'Unexpected runtime connection';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_user AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole))
       OR pg_has_role(current_user, 'lms_user', 'MEMBER')
       OR has_schema_privilege(current_user, 'public', 'CREATE') THEN
        RAISE EXCEPTION 'Runtime has privileged attributes';
    END IF;
    FOREACH audit_table IN ARRAY ARRAY['public.platform_audit_logs', 'public.tenant_audit_logs'] LOOP
        IF NOT has_table_privilege(current_user, audit_table, 'SELECT')
           OR NOT has_table_privilege(current_user, audit_table, 'INSERT') THEN
            RAISE EXCEPTION 'Runtime lacks required audit privileges';
        END IF;
        FOREACH forbidden_privilege IN ARRAY ARRAY['UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] LOOP
            IF has_table_privilege(current_user, audit_table, forbidden_privilege) THEN
                RAISE EXCEPTION 'Runtime has forbidden audit privileges';
            END IF;
        END LOOP;
    END LOOP;
END
$verify$;
SELECT 'PASS: runtime authentication and audit privileges verified' AS result;
SQL

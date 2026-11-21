\set ON_ERROR_STOP on
BEGIN;

DO $guard$
DECLARE
    runtime_role record;
BEGIN
    IF current_database() <> 'lms_saas' OR current_user <> 'lms_user' THEN
        RAISE EXCEPTION 'Refusing audit grants outside the local owner/database';
    END IF;
    SELECT oid, rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolcanlogin, rolbypassrls
    INTO runtime_role FROM pg_roles WHERE rolname = 'lms_runtime';
    IF runtime_role IS NULL OR runtime_role.rolsuper OR runtime_role.rolcreatedb
       OR runtime_role.rolcreaterole OR runtime_role.rolinherit OR NOT runtime_role.rolcanlogin OR runtime_role.rolbypassrls THEN
        RAISE EXCEPTION 'Runtime role is missing or has unsafe attributes';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_auth_members WHERE member = runtime_role.oid)
       OR EXISTS (SELECT 1 FROM pg_database WHERE datname = current_database() AND datdba = runtime_role.oid)
       OR EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = 'public' AND c.relowner = runtime_role.oid)
       OR EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'public' AND nspowner = runtime_role.oid) THEN
        RAISE EXCEPTION 'Runtime must not inherit roles or own public objects';
    END IF;
END
$guard$;

DO $database_grant$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO lms_runtime', current_database());
END
$database_grant$;

-- Runtime DML for existing application tables; future migrations must reapply grants.
GRANT USAGE ON SCHEMA public TO lms_runtime;
REVOKE CREATE ON SCHEMA public FROM PUBLIC, lms_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lms_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lms_runtime;
REVOKE ALL ON public.platform_audit_logs, public.tenant_audit_logs FROM PUBLIC, lms_runtime;
GRANT SELECT, INSERT ON public.platform_audit_logs, public.tenant_audit_logs TO lms_runtime;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON public.migrations FROM PUBLIC, lms_runtime;
GRANT SELECT ON public.migrations TO lms_runtime;

DO $verify$
DECLARE
    audit_table text;
    forbidden_privilege text;
BEGIN
    IF has_schema_privilege('lms_runtime', 'public', 'CREATE')
       OR pg_has_role('lms_runtime', 'lms_user', 'MEMBER') THEN
        RAISE EXCEPTION 'Runtime can create schema objects or assume owner role';
    END IF;
    FOREACH audit_table IN ARRAY ARRAY['public.platform_audit_logs', 'public.tenant_audit_logs'] LOOP
        IF NOT has_table_privilege('lms_runtime', audit_table, 'SELECT')
           OR NOT has_table_privilege('lms_runtime', audit_table, 'INSERT') THEN
            RAISE EXCEPTION 'Runtime lacks audit read or insert privilege';
        END IF;
        FOREACH forbidden_privilege IN ARRAY ARRAY['UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] LOOP
            IF has_table_privilege('lms_runtime', audit_table, forbidden_privilege) THEN
                RAISE EXCEPTION 'Unsafe runtime privilege % on %', forbidden_privilege, audit_table;
            END IF;
        END LOOP;
    END LOOP;
END
$verify$;

COMMIT;

-- Proof of concept only: changes are rolled back; this does not harden the live app role.
-- Requires the explicitly named test database and a superuser-capable test operator.
\set ON_ERROR_STOP on
BEGIN;
SELECT pg_advisory_xact_lock(hashtextextended('m1-audit-privilege-probe-20261121', 0));
DO $guard$
BEGIN
    IF current_database() <> 'lms_saas_test' OR current_user <> 'lms_user' THEN
        RAISE EXCEPTION 'Refusing audit probe outside the dedicated test database';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_audit_probe_20261121') THEN
        RAISE EXCEPTION 'Probe role already exists';
    END IF;
END
$guard$;

CREATE ROLE lms_audit_probe_20261121 NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
REVOKE ALL ON public.platform_audit_logs, public.tenant_audit_logs FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO lms_audit_probe_20261121;
GRANT SELECT, INSERT ON public.platform_audit_logs, public.tenant_audit_logs TO lms_audit_probe_20261121;
GRANT USAGE, SELECT ON SEQUENCE public.platform_audit_logs_id_seq, public.tenant_audit_logs_id_seq TO lms_audit_probe_20261121;

-- Explicit IDs avoid consuming PostgreSQL sequences even if the transaction is rolled back.
INSERT INTO public.tenants (id, public_ulid, slug, name, status, created_at, updated_at)
VALUES (900000001, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'audit-probe-a', 'Synthetic A', 'active', now(), now()),
       (900000002, '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'audit-probe-b', 'Synthetic B', 'active', now(), now());
INSERT INTO public.users (id, public_ulid, name, status, auth_version, created_at, updated_at)
VALUES (900000001, '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'Synthetic User', 'pending', 1, now(), now());
INSERT INTO public.tenant_memberships (id, tenant_id, user_id, local_username, local_username_normalized, display_name, status, auth_version, created_at, updated_at)
SELECT 900000001, t.id, u.id, 'probe-user', 'probe-user', 'Synthetic User', 'invited', 1, now(), now()
FROM public.tenants t CROSS JOIN public.users u
WHERE t.slug = 'audit-probe-a' AND u.public_ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
SELECT set_config('lms.audit_probe_tenant', id::text, true) FROM public.tenants WHERE slug='audit-probe-a';

SET LOCAL ROLE lms_audit_probe_20261121;
INSERT INTO public.platform_audit_logs (id, action, target_type, metadata_json)
VALUES (900000001, 'test.audit.probe', 'synthetic', '{}');
INSERT INTO public.tenant_audit_logs (id, tenant_id, action, target_type, metadata_json)
VALUES (900000001, current_setting('lms.audit_probe_tenant')::bigint, 'test.audit.probe', 'synthetic', '{}');

DO $probe$
DECLARE
    table_name text;
    operation text;
    row_count bigint;
BEGIN
    IF current_user <> 'lms_audit_probe_20261121'
       OR pg_has_role(current_user, 'lms_user', 'MEMBER') THEN
        RAISE EXCEPTION 'Probe has unsafe owner role membership';
    END IF;
    FOREACH table_name IN ARRAY ARRAY['platform_audit_logs', 'tenant_audit_logs'] LOOP
        IF NOT has_table_privilege(current_user, 'public.' || table_name, 'SELECT')
           OR NOT has_table_privilege(current_user, 'public.' || table_name, 'INSERT') THEN
            RAISE EXCEPTION 'Missing required audit read or insert privilege for %', table_name;
        END IF;
        EXECUTE format('SELECT count(*) FROM public.%I', table_name) INTO row_count;
        IF row_count < 1 THEN
            RAISE EXCEPTION 'Synthetic audit entry was not readable in %', table_name;
        END IF;
        FOREACH operation IN ARRAY ARRAY['UPDATE', 'DELETE', 'TRUNCATE', 'TRIGGER'] LOOP
            IF has_table_privilege(current_user, 'public.' || table_name, operation) THEN
                RAISE EXCEPTION 'Unexpected % privilege on %', operation, table_name;
            END IF;
        END LOOP;
        BEGIN
            EXECUTE format('UPDATE public.%I SET action = action', table_name);
            RAISE EXCEPTION 'UPDATE unexpectedly succeeded for %', table_name;
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('DELETE FROM public.%I', table_name);
            RAISE EXCEPTION 'DELETE unexpectedly succeeded for %', table_name;
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('TRUNCATE TABLE public.%I', table_name);
            RAISE EXCEPTION 'TRUNCATE unexpectedly succeeded for %', table_name;
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER ALL', table_name);
            RAISE EXCEPTION 'ALTER TABLE unexpectedly succeeded for %', table_name;
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
    END LOOP;
END
$probe$;

RESET ROLE;
DO $fk$
BEGIN
    BEGIN
        INSERT INTO public.tenant_audit_logs (id, tenant_id, actor_membership_id, action, target_type, metadata_json)
        SELECT 900000002, b.id, m.id, 'test.cross_tenant', 'synthetic', '{}'
        FROM public.tenants a JOIN public.tenant_memberships m ON m.tenant_id = a.id
        CROSS JOIN public.tenants b WHERE a.slug='audit-probe-a' AND b.slug='audit-probe-b';
        RAISE EXCEPTION 'Cross-tenant membership unexpectedly accepted';
    EXCEPTION WHEN foreign_key_violation THEN NULL;
    END;
END
$fk$;
ROLLBACK;
SELECT 'PASS: append, deny mutations, deny ownership changes, cross-tenant FK; transaction rolled back' AS result;

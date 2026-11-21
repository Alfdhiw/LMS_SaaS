-- Executed inside the uncommitted transaction of the runtime-grants test only.
DO $guard$
BEGIN
    IF current_database() <> 'lms_saas_test' OR current_user <> 'lms_user' THEN
        RAISE EXCEPTION 'Refusing assertions outside the dedicated test database';
    END IF;
END
$guard$;

INSERT INTO public.tenants (id, public_ulid, slug, name, status, created_at, updated_at)
VALUES (900000101, '01ARZ3NDEKTSV4RRFFQ69G5FAZ', 'audit-grant-test', 'Synthetic Test', 'active', now(), now());
SET LOCAL ROLE lms_runtime;
INSERT INTO public.platform_audit_logs (id, action, target_type, metadata_json)
VALUES (900000101, 'test.audit.grants', 'synthetic', '{}');
INSERT INTO public.tenant_audit_logs (id, tenant_id, action, target_type, metadata_json)
VALUES (900000101, 900000101, 'test.audit.grants', 'synthetic', '{}');

DO $probe$
DECLARE
    audit_table text;
    row_count bigint;
BEGIN
    IF NOT has_sequence_privilege(current_user, 'public.platform_audit_logs_id_seq', 'USAGE')
       OR NOT has_sequence_privilege(current_user, 'public.tenant_audit_logs_id_seq', 'USAGE') THEN
        RAISE EXCEPTION 'Audit sequence privilege missing';
    END IF;
    FOREACH audit_table IN ARRAY ARRAY['platform_audit_logs', 'tenant_audit_logs'] LOOP
        EXECUTE format('SELECT count(*) FROM public.%I', audit_table) INTO row_count;
        IF row_count < 1 THEN RAISE EXCEPTION 'Audit read failed'; END IF;
        BEGIN
            EXECUTE format('UPDATE public.%I SET action = action', audit_table);
            RAISE EXCEPTION 'UPDATE unexpectedly succeeded';
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('DELETE FROM public.%I', audit_table);
            RAISE EXCEPTION 'DELETE unexpectedly succeeded';
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('TRUNCATE public.%I', audit_table);
            RAISE EXCEPTION 'TRUNCATE unexpectedly succeeded';
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
        BEGIN
            EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER ALL', audit_table);
            RAISE EXCEPTION 'ALTER unexpectedly succeeded';
        EXCEPTION WHEN insufficient_privilege THEN NULL;
        END;
    END LOOP;
    BEGIN
        CREATE TABLE public.audit_grant_forbidden (id integer);
        RAISE EXCEPTION 'CREATE unexpectedly succeeded';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END
$probe$;
RESET ROLE;
ROLLBACK;
SELECT 'PASS: runtime grants and audit mutations were checked; transaction rolled back' AS result;

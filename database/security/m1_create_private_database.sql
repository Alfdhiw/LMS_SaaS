\set ON_ERROR_STOP on
\set AUTOCOMMIT on

-- Called only through the guarded local operator wrapper. The SQL guards are
-- defense in depth; matching names/roles alone do not identify a local server.
SELECT set_config('lms.private_database_target', :'target_db', false) AS target \gset

DO $preflight$
DECLARE
    target text := current_setting('lms.private_database_target', true);
    runtime_role record;
BEGIN
    IF target IS NULL OR target !~ '^lms_(restore|aux)_[a-z0-9_]{1,35}$'
       OR target ~ '[^a-z0-9_]' THEN
        RAISE EXCEPTION 'Invalid private database name';
    END IF;
    IF current_database() <> 'postgres' OR current_user <> 'lms_user'
       OR current_setting('server_version_num')::integer / 10000 <> 17
       OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='lms_user' AND rolsuper AND rolcanlogin)
       OR NOT EXISTS (SELECT 1 FROM pg_database WHERE datname='lms_saas' AND pg_get_userbyid(datdba)='lms_user') THEN
        RAISE EXCEPTION 'Unexpected local PostgreSQL owner or database';
    END IF;
    SELECT rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolcanlogin, rolreplication, rolbypassrls
      INTO runtime_role FROM pg_roles WHERE rolname='lms_runtime';
    IF runtime_role IS NULL OR runtime_role.rolsuper OR runtime_role.rolcreatedb
       OR runtime_role.rolcreaterole OR runtime_role.rolinherit OR NOT runtime_role.rolcanlogin OR runtime_role.rolreplication
       OR runtime_role.rolbypassrls OR EXISTS (SELECT 1 FROM pg_auth_members
           WHERE member=(SELECT oid FROM pg_roles WHERE rolname='lms_runtime'))
       OR NOT has_database_privilege('lms_runtime','lms_saas','CONNECT') THEN
        RAISE EXCEPTION 'Unexpected runtime role or application database access';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_database WHERE datname=target) THEN
        RAISE EXCEPTION 'Target database already exists; no changes made';
    END IF;
END
$preflight$;

-- CREATE DATABASE cannot run inside a transaction. A failure at any point
-- before COMMIT leaves a new, closed database; never delete it automatically.
CREATE DATABASE :"target_db" WITH TEMPLATE template0 ALLOW_CONNECTIONS false;

BEGIN;
REVOKE CONNECT ON DATABASE :"target_db" FROM PUBLIC;
DO $closed_check$
DECLARE
    target text := current_setting('lms.private_database_target');
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_database WHERE datname=target AND NOT datallowconn
          AND NOT datistemplate AND pg_get_userbyid(datdba)='lms_user')
       OR has_database_privilege('lms_runtime',target,'CONNECT')
       OR EXISTS (SELECT 1 FROM pg_database d
          CROSS JOIN LATERAL aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
          WHERE d.datname=target AND a.grantee=0 AND a.privilege_type='CONNECT') THEN
        RAISE EXCEPTION 'Private database pre-open checks failed';
    END IF;
END
$closed_check$;

ALTER DATABASE :"target_db" ALLOW_CONNECTIONS true;
DO $open_check$
DECLARE
    target text := current_setting('lms.private_database_target');
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_database WHERE datname=target AND datallowconn
          AND NOT datistemplate AND pg_get_userbyid(datdba)='lms_user')
       OR has_database_privilege('lms_runtime',target,'CONNECT')
       OR NOT has_database_privilege('lms_user',target,'CONNECT')
       OR EXISTS (SELECT 1 FROM pg_database d
          CROSS JOIN LATERAL aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
          WHERE d.datname=target AND a.grantee=0 AND a.privilege_type='CONNECT') THEN
        RAISE EXCEPTION 'Private database post-open checks failed';
    END IF;
END
$open_check$;
COMMIT;
SELECT 'PASS: private database created; owner allowed, runtime CONNECT denied' AS result;

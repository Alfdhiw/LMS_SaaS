<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $connection = config('database.connections.pgsql');
    if (! $app->environment('local') || config('database.default') !== 'pgsql'
        || ($connection['host'] ?? null) !== 'db'
        || ($connection['database'] ?? null) !== 'lms_saas'
        || ($connection['username'] ?? null) !== 'lms_runtime'
        || ! empty($connection['url'])) {
        throw new RuntimeException('Unexpected local database configuration.');
    }

    $role = DB::selectOne(<<<'SQL'
        SELECT current_database() AS database_name, current_user AS role_name,
            (r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolinherit OR r.rolbypassrls)::int AS unsafe,
            (EXISTS (SELECT 1 FROM pg_auth_members WHERE member = r.oid))::int AS has_memberships,
            (has_schema_privilege(current_user, 'public', 'CREATE'))::int AS can_create,
            (EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relowner = r.oid))::int AS owns_objects,
            (EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspname = 'public' AND n.nspowner = r.oid))::int AS owns_schema,
            (EXISTS (SELECT 1 FROM pg_database d WHERE d.datname = current_database() AND d.datdba = r.oid))::int AS owns_database
        FROM pg_roles r WHERE r.rolname = current_user
        SQL);
    if (! $role || $role->database_name !== 'lms_saas' || $role->role_name !== 'lms_runtime'
        || $role->unsafe || $role->has_memberships || $role->can_create || $role->owns_objects
        || $role->owns_schema || $role->owns_database) {
        throw new RuntimeException('Unsafe runtime role.');
    }

    foreach (['public.platform_audit_logs', 'public.tenant_audit_logs'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
            $result = DB::selectOne('SELECT has_table_privilege(current_user, ?, ?)::int AS allowed', [$table, $privilege]);
            if ((bool) $result->allowed !== in_array($privilege, ['SELECT', 'INSERT'], true)) {
                throw new RuntimeException('Unexpected audit privilege.');
            }
        }
    }
    echo "PASS: local runtime connection and audit privileges verified.\n";
} catch (Throwable $exception) {
    // Never print connection details, SQL bindings, or credentials from exceptions.
    fwrite(STDERR, "FAIL: runtime connection or privilege verification failed; check local configuration.\n");
    exit(1);
}

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '../..')

$grantSql = Get-Content -Raw -Encoding UTF8 'database/security/m1_audit_runtime_grants.sql'
$assertions = Get-Content -Raw -Encoding UTF8 'tests/Support/m1_audit_runtime_grants_assertions.sql'
$expectedGuard = "current_database() <> 'lms_saas'"
if (-not $grantSql.Contains($expectedGuard) -or -not $grantSql.Contains('lms_runtime') -or -not $grantSql.Contains('BEGIN;') -or -not $grantSql.Contains('COMMIT;')) {
    throw 'Skrip grant tidak sesuai kontrak uji; tidak ada perintah yang dijalankan.'
}

# A fresh role exists only in this rolled-back test transaction. Do not replace
# the test database guard, or persist the trial role/grants on any database.
$grantSql = $grantSql.Replace($expectedGuard, "current_database() <> 'lms_saas_test'")
$grantSql = $grantSql.Replace('lms_runtime', 'lms_audit_runtime_probe')
$assertions = $assertions.Replace('lms_runtime', 'lms_audit_runtime_probe')
$grantSql = $grantSql.Replace('BEGIN;', "BEGIN;`nCREATE ROLE lms_audit_runtime_probe LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;")
$grantSql = $grantSql.Replace('COMMIT;', $assertions)
$grantSql | & "$PSScriptRoot/../../scripts/m1-db-owner-command.ps1" -Mode test

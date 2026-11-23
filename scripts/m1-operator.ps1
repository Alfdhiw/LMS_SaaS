param(
    [Parameter(Mandatory)]
    [ValidateSet('migrate', 'seed', 'test', 'test-m0')]
    [string] $Operation
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot
$ownerSecret = Join-Path (Split-Path -Parent $projectRoot) '.lms-saas-secrets/postgres_owner_password'
if (-not (Test-Path -LiteralPath $ownerSecret -PathType Leaf)) {
    throw 'File kredensial owner lokal tidak ditemukan; operasi dibatalkan.'
}
if (-not (Test-Path -LiteralPath 'docker-compose.override.yml' -PathType Leaf)) {
    throw 'Override role lokal tidak aktif; operasi owner dibatalkan.'
}

# This container exists only for the requested operation; the running app,
# worker and scheduler do not receive the owner secret mount or environment.
docker compose run --rm --no-deps --volume "${ownerSecret}:/run/secrets/postgres_owner_password:ro" --entrypoint sh app scripts/m1-owner-exec.sh $Operation
if ($LASTEXITCODE -ne 0) { throw "Operasi owner $Operation gagal dengan exit code $LASTEXITCODE." }

if ($Operation -eq 'migrate') {
    Get-Content -Raw -Encoding UTF8 'database/security/m1_audit_runtime_grants.sql' |
        docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas
    if ($LASTEXITCODE -ne 0) { throw 'Migration selesai, tetapi penerapan ulang hak runtime gagal; hentikan rollout.' }
}

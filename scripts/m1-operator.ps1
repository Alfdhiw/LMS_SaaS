param(
    [Parameter(Mandatory)]
    [ValidateSet('migrate', 'seed', 'test', 'test-m0')]
    [string] $Operation,
    [ValidatePattern('^[A-Za-z0-9_()|]{1,200}$')]
    [string] $TestFilter
)

$ErrorActionPreference = 'Stop'
if ($TestFilter -and $Operation -ne 'test') { throw 'TestFilter hanya tersedia untuk operasi test.' }
$testArguments = @()
if ($TestFilter) { $testArguments = @('--filter', $TestFilter) }
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
docker compose run --rm --no-deps --volume "${ownerSecret}:/run/secrets/postgres_owner_password:ro" --entrypoint sh app scripts/m1-owner-exec.sh $Operation @testArguments
if ($LASTEXITCODE -ne 0) { throw "Operasi owner $Operation gagal dengan exit code $LASTEXITCODE." }

if ($Operation -eq 'migrate') {
    try {
        Get-Content -Raw -Encoding UTF8 'database/security/m1_audit_runtime_grants.sql' |
            & "$PSScriptRoot/m1-db-owner-command.ps1" -Mode grants
    } catch {
        throw 'Migration selesai, tetapi penerapan ulang hak runtime gagal; hentikan rollout.'
    }
}

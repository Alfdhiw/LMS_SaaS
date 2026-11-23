$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot
$secretRoot = Join-Path (Split-Path -Parent $projectRoot) '.lms-saas-secrets'
$ownerSecret = Join-Path $secretRoot 'postgres_owner_password'
$runtimeSecret = Join-Path $secretRoot 'postgres_runtime_password'
$provisionScript = Join-Path $projectRoot 'scripts/m1-provision-runtime.sh'
$grantScript = Join-Path $projectRoot 'database/security/m1_audit_runtime_grants.sql'

if (-not (Test-Path -LiteralPath $ownerSecret -PathType Leaf) -or (Test-Path -LiteralPath $runtimeSecret)) {
    throw 'Owner secret tidak ada atau runtime secret sudah ada; provisioning dibatalkan.'
}
if (-not (Test-Path -LiteralPath '.env' -PathType Leaf) -or (Test-Path -LiteralPath 'docker-compose.override.yml')) {
    throw 'Konfigurasi lokal tidak sesuai atau override sudah ada; provisioning dibatalkan.'
}
$envLines = [System.IO.File]::ReadAllLines((Join-Path $projectRoot '.env'))
foreach ($required in @('APP_ENV=local', 'DB_HOST=db', 'DB_DATABASE=lms_saas', 'DB_USERNAME=lms_user')) {
    $key = $required.Split('=')[0]
    if (@($envLines | Where-Object { $_ -ceq $required }).Count -ne 1 -or
        @($envLines | Where-Object { $_ -match ('^' + [regex]::Escape($key) + '=') }).Count -ne 1) {
        throw 'Target .env tidak cocok dengan preflight lokal; provisioning dibatalkan.'
    }
}
if (@($envLines | Where-Object { $_ -match '^DB_URL\s*=\s*[^\s]+\s*$' }).Count -gt 0) {
    throw 'DB_URL tidak boleh aktif selama provisioning role lokal.'
}
$envLines = $null
$secretAcl = Get-Acl -LiteralPath $secretRoot
if (-not $secretAcl.AreAccessRulesProtected) {
    throw 'ACL folder secret masih mewarisi izin; provisioning dibatalkan.'
}
foreach ($rule in $secretAcl.Access) {
    if ($rule.AccessControlType -eq 'Allow') {
        $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
        if ($sid -in @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')) {
            throw 'ACL folder secret memberikan akses terlalu luas; provisioning dibatalkan.'
        }
    }
}

$runtimeExists = docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas -tAc "SELECT count(*) FROM pg_roles WHERE rolname='lms_runtime'"
if ($LASTEXITCODE -ne 0 -or ($runtimeExists | Out-String).Trim() -ne '0') {
    throw 'Role runtime sudah ada atau pemeriksaan PostgreSQL gagal; provisioning dibatalkan.'
}

$bytes = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
$runtimePassword = -join ($bytes | ForEach-Object { $_.ToString('x2') })
[Array]::Clear($bytes, 0, $bytes.Length)
$file = [System.IO.File]::Open($runtimeSecret, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
try {
    $writer = [System.IO.StreamWriter]::new($file, [System.Text.UTF8Encoding]::new($false))
    try { $writer.Write($runtimePassword) } finally { $writer.Dispose() }
} finally { $file.Dispose(); $runtimePassword = $null }

# No secret is passed in arguments or printed. The disposable client receives
# read-only mounts and has no mount of the application or its .env file.
docker run --rm --network lms-saas-local_default `
    --mount "type=bind,src=$ownerSecret,dst=/run/secrets/postgres_owner_password,readonly" `
    --mount "type=bind,src=$runtimeSecret,dst=/run/secrets/postgres_runtime_password,readonly" `
    --mount "type=bind,src=$provisionScript,dst=/run/m1-provision-runtime.sh,readonly" `
    --mount "type=bind,src=$grantScript,dst=/run/m1_audit_runtime_grants.sql,readonly" `
    --entrypoint sh postgres:17-alpine /run/m1-provision-runtime.sh
if ($LASTEXITCODE -ne 0) {
    throw 'Provisioning belum selesai. Jangan alihkan .env; simpan secret dan periksa status role.'
}
Write-Host 'Runtime role siap diverifikasi untuk cutover; .env belum diubah.'

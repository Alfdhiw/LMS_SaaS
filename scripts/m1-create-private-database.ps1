[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('\Alms_(restore|aux)_[a-z0-9_]{1,35}\z')]
    [string] $DatabaseName
)

$ErrorActionPreference = 'Stop'
if ($DatabaseName -cnotmatch '\Alms_(restore|aux)_[a-z0-9_]{1,35}\z') {
    throw 'Database name must contain only lowercase ASCII letters, numbers, and underscores.'
}
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

# Refuse remote or overridden Docker/Compose targets; no credentials are printed.
foreach ($name in @('DOCKER_HOST','DOCKER_CONTEXT','COMPOSE_FILE','COMPOSE_PROJECT_NAME','COMPOSE_ENV_FILES')) {
    if (-not [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) {
        throw "Environment override $name is not allowed for this local-only operation."
    }
}
$contextName = (docker context show | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $contextName -ne 'desktop-linux') { throw 'Expected the local Docker Desktop Linux context.' }
$endpoint = docker context inspect desktop-linux --format '{{json .Endpoints.docker.Host}}' | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or $endpoint -ne 'npipe:////./pipe/dockerDesktopLinuxEngine') {
    throw 'Expected the local Docker Desktop named pipe.'
}
if (-not (Test-Path -LiteralPath '.env' -PathType Leaf) -or
    -not (Test-Path -LiteralPath 'docker-compose.override.yml' -PathType Leaf)) {
    throw 'Local runtime configuration or override is missing.'
}
$configLines = [IO.File]::ReadAllLines((Join-Path $projectRoot '.env'))
foreach ($required in @('APP_ENV=local','DB_CONNECTION=pgsql','DB_HOST=db','DB_DATABASE=lms_saas','DB_USERNAME=lms_runtime')) {
    $key = $required.Split('=')[0]
    if (@($configLines | Where-Object { $_ -ceq $required }).Count -ne 1 -or
        @($configLines | Where-Object { $_ -match ('^' + [regex]::Escape($key) + '=') }).Count -ne 1) {
        throw 'Unexpected local .env target; private database creation cancelled.'
    }
}
if (@($configLines | Where-Object { $_ -match '^DB_URL\s*=\s*[^\s]+\s*$' }).Count -gt 0) {
    throw 'DB_URL must be empty for this operation.'
}
$configLines = $null

$databaseId = (docker compose ps -q db | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $databaseId -notmatch '^[a-f0-9]{64}$') { throw 'Expected one running Compose database.' }
$labels = docker inspect --format '{{json .Config.Labels}}' $databaseId | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or $labels.'com.docker.compose.project' -ne 'lms-saas-local' -or
    $labels.'com.docker.compose.service' -ne 'db') { throw 'Unexpected Compose database labels.' }
$mounts = docker inspect --format '{{json .Mounts}}' $databaseId | ConvertFrom-Json
$dataMount = @($mounts | Where-Object { $_.Destination -eq '/var/lib/postgresql/data' })
if ($LASTEXITCODE -ne 0 -or $dataMount.Count -ne 1 -or $dataMount[0].Type -ne 'volume' -or
    $dataMount[0].Name -ne 'lms-saas-local_postgres_data') { throw 'Unexpected PostgreSQL data volume.' }

if (-not $PSCmdlet.ShouldProcess($DatabaseName, 'Create new private PostgreSQL database on local Docker Desktop')) {
    return
}

$previousPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    Get-Content -LiteralPath 'database/security/m1_create_private_database.sql' -Raw -Encoding UTF8 |
        docker exec -i $databaseId psql -X -v ON_ERROR_STOP=1 -v "target_db=$DatabaseName" -U lms_user -d postgres
    $result = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousPreference
}
if ($result -ne 0) {
    throw "Private database creation failed. Do not retry blindly: inspect $DatabaseName and its connection state; never delete an existing database automatically."
}
Write-Host "Private local database $DatabaseName is ready for an approved owner-only workflow."

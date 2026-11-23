param(
    [Parameter(Mandatory)]
    [ValidateSet('private', 'grants', 'test', 'backup', 'restore')]
    [string] $Mode,
    [string] $Target,
    [string] $BackupName,
    [string] $ExpectedContainerId,
    [Parameter(ValueFromPipeline = $true)]
    [string] $SqlText
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot
if ($Mode -in @('private', 'restore') -and $Target -cnotmatch '\Alms_(restore|aux)_[a-z0-9_]{1,35}\z') {
    throw 'Invalid private database name.'
}
if ($Mode -notin @('private', 'restore') -and $Target) { throw 'Unexpected target.' }
if ($Mode -in @('private', 'grants', 'test') -and [string]::IsNullOrWhiteSpace($SqlText)) { throw 'SQL input is required.' }
if ($Mode -in @('backup', 'restore') -and ($BackupName -cnotmatch '\Alms_backup_[a-z0-9_]{1,50}\.dump\z' -or $SqlText)) { throw 'Invalid backup filename or unexpected SQL.' }
if ($Mode -notin @('backup', 'restore') -and $BackupName) { throw 'Unexpected backup filename.' }
$databaseId = (docker compose ps -q db | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $databaseId -notmatch '^[a-f0-9]{64}$') { throw 'Expected one running Compose database.' }
if ($ExpectedContainerId -and $databaseId -cne $ExpectedContainerId) { throw 'Database container changed after preflight.' }
$labels = docker inspect --format '{{json .Config.Labels}}' $databaseId | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or $labels.'com.docker.compose.project' -ne 'lms-saas-local' -or
    $labels.'com.docker.compose.service' -ne 'db') { throw 'Unexpected Compose database labels.' }
$command = (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'm1-db-owner-command.sh') -Raw -Encoding UTF8).Replace("`r`n", "`n")
if ($PSVersionTable.PSVersion.Major -lt 7) { $command = $command.Replace('"', '\"') }
$arguments = @($Mode)
if ($Mode -eq 'private') { $arguments += $Target }
if ($Mode -in @('backup', 'restore')) { $arguments += $BackupName }
if ($Mode -eq 'restore') { $arguments += $Target }
$savedPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    if ($Mode -in @('backup', 'restore')) {
        docker exec -i $databaseId sh -c $command -- @arguments
    } else {
        $SqlText | docker exec -i $databaseId sh -c $command -- @arguments
    }
    $result = $LASTEXITCODE
} finally { $ErrorActionPreference = $savedPreference }
if ($result -ne 0) { throw "Owner database operation failed (exit $result)." }

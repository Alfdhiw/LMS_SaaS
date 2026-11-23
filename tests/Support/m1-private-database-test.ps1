$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location $projectRoot
$sql = (Get-Content -LiteralPath 'database/security/m1_create_private_database.sql' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$scratchName = 'lms-private-db-test-' + [guid]::NewGuid().ToString('N')
$scratchId = $null

function Invoke-SyntheticSql {
    param([string] $Text, [string] $Target, [string] $User = 'lms_user', [string] $Database = 'postgres')
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @($Text | docker exec -i $scratchId psql -X -v ON_ERROR_STOP=1 -v "target_db=$Target" -U $User -d $Database 2>&1 | ForEach-Object { $_.ToString() })
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $savedPreference
    }
    return [pscustomobject]@{ Code = $code; Output = ($output -join "`n") }
}

function Get-SyntheticScalar {
    param([string] $Query, [string] $Database = 'postgres', [string] $User = 'lms_user')
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(docker exec $scratchId psql -X -v ON_ERROR_STOP=1 -U $User -d $Database -tAc $Query 2>&1 | ForEach-Object { $_.ToString() })
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $savedPreference
    }
    return [pscustomobject]@{ Code = $code; Output = (($output -join "`n").Trim()) }
}

function Assert-Synthetic {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Host "PASS: $Message"
}

try {
    $scratchId = (docker run --detach --rm --pull never --name $scratchName --network none `
        --tmpfs '/var/lib/postgresql/data:rw,size=256m' -e POSTGRES_USER=lms_user `
        -e POSTGRES_PASSWORD=synthetic-private-test-only -e POSTGRES_DB=lms_saas postgres:17-alpine | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $scratchId -notmatch '^[a-f0-9]{64}$') { $scratchId = $null; throw 'Unable to start isolated PostgreSQL.' }
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        docker exec $scratchId pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Isolated PostgreSQL was not ready.' }
    $setup = Get-SyntheticScalar "CREATE ROLE lms_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
    Assert-Synthetic ($setup.Code -eq 0) 'Synthetic runtime role created on isolated server'
    $sourceAclBefore = Get-SyntheticScalar "SELECT COALESCE(datacl::text,'[NULL]') FROM pg_database WHERE datname='lms_saas'"
    Assert-Synthetic ($sourceAclBefore.Code -eq 0 -and $sourceAclBefore.Output -ne '') 'Source database ACL recorded'

    $invalid = Invoke-SyntheticSql $sql 'lms_saas'
    Assert-Synthetic ($invalid.Code -ne 0 -and $invalid.Output -match 'Invalid private database name') 'Existing application database name is rejected'
    $invalidSyntax = Invoke-SyntheticSql $sql 'lms_restore_a;DROP DATABASE lms_saas'
    Assert-Synthetic ($invalidSyntax.Code -ne 0 -and $invalidSyntax.Output -match 'Invalid private database name') 'SQL-like target name is rejected before creation'
    $invalidUpper = Invoke-SyntheticSql $sql 'lms_restore_UPPER'
    Assert-Synthetic ($invalidUpper.Code -ne 0 -and $invalidUpper.Output -match 'Invalid private database name') 'Uppercase target name is rejected before creation'
    $wrongDatabase = Invoke-SyntheticSql $sql 'lms_restore_wrong_database' 'lms_user' 'lms_saas'
    Assert-Synthetic ($wrongDatabase.Code -ne 0 -and $wrongDatabase.Output -match 'Unexpected local PostgreSQL') 'Unexpected operator database is rejected'
    $wrongRole = Invoke-SyntheticSql $sql 'lms_restore_wrong_role' 'lms_runtime' 'postgres'
    Assert-Synthetic ($wrongRole.Code -ne 0 -and $wrongRole.Output -match 'Unexpected local PostgreSQL') 'Runtime role cannot create a database'
    $unsafeRole = Get-SyntheticScalar 'ALTER ROLE lms_runtime REPLICATION'
    Assert-Synthetic ($unsafeRole.Code -eq 0) 'Synthetic unsafe runtime role configured for guard test'
    $unsafeResult = Invoke-SyntheticSql $sql 'lms_aux_unsafe_role'
    $safeRole = Get-SyntheticScalar 'ALTER ROLE lms_runtime NOREPLICATION'
    Assert-Synthetic ($unsafeResult.Code -ne 0 -and $unsafeResult.Output -match 'Unexpected runtime role' -and $safeRole.Code -eq 0) 'Unsafe runtime replication privilege is rejected'
    $absent = Get-SyntheticScalar "SELECT count(*) FROM pg_database WHERE datname IN ('lms_restore_wrong_database','lms_restore_wrong_role','lms_aux_unsafe_role')"
    Assert-Synthetic ($absent.Code -eq 0 -and $absent.Output -eq '0') 'Rejected requests created no databases'

    foreach ($target in @('lms_restore_synthetic','lms_aux_synthetic')) {
        $created = Invoke-SyntheticSql $sql $target
        Assert-Synthetic ($created.Code -eq 0) "$target created by exact checked-in SQL"
        $state = Get-SyntheticScalar "SELECT datallowconn::int || '|' || has_database_privilege('lms_runtime',oid,'CONNECT')::int || '|' || has_database_privilege('lms_user',oid,'CONNECT')::int || '|' || has_database_privilege('lms_runtime',oid,'TEMP')::int FROM pg_database WHERE datname='$target'"
        Assert-Synthetic ($state.Code -eq 0 -and $state.Output -eq '1|0|1|1') "$target is open, runtime denied, owner allowed, TEMP unchanged"
        $ownerConnection = Get-SyntheticScalar 'SELECT current_user' $target 'lms_user'
        Assert-Synthetic ($ownerConnection.Code -eq 0 -and $ownerConnection.Output -eq 'lms_user') "Owner can connect to $target"
        $runtimeConnection = Get-SyntheticScalar 'SELECT current_user' $target 'lms_runtime'
        Assert-Synthetic ($runtimeConnection.Code -ne 0 -and $runtimeConnection.Output -match 'permission denied for database') "Runtime cannot connect to $target"
    }

    $beforeCollision = Get-SyntheticScalar "SELECT datallowconn::text || '|' || datacl::text FROM pg_database WHERE datname='lms_restore_synthetic'"
    $collision = Invoke-SyntheticSql $sql 'lms_restore_synthetic'
    $afterCollision = Get-SyntheticScalar "SELECT datallowconn::text || '|' || datacl::text FROM pg_database WHERE datname='lms_restore_synthetic'"
    Assert-Synthetic ($collision.Code -ne 0 -and $collision.Output -match 'already exists' -and $beforeCollision.Output -eq $afterCollision.Output) 'Existing target is not modified'

    $createLine = 'CREATE DATABASE :"target_db" WITH TEMPLATE template0 ALLOW_CONNECTIONS false;'
    if (($sql.Split(@($createLine), [StringSplitOptions]::None).Count - 1) -ne 1) { throw 'Create-stage fault injection marker changed.' }
    $failedAfterCreate = $sql.Replace($createLine, $createLine + "`nSELECT 1 / 0;")
    $failure = Invoke-SyntheticSql $failedAfterCreate 'lms_restore_failure_create'
    $state = Get-SyntheticScalar "SELECT datallowconn::int FROM pg_database WHERE datname='lms_restore_failure_create'"
    Assert-Synthetic ($failure.Code -ne 0 -and $state.Output -eq '0') 'Failure immediately after CREATE leaves database closed'

    $openLine = 'ALTER DATABASE :"target_db" ALLOW_CONNECTIONS true;'
    if (($sql.Split(@($openLine), [StringSplitOptions]::None).Count - 1) -ne 1) { throw 'Open-stage fault injection marker changed.' }
    $failedBeforeOpen = $sql.Replace($openLine, "SELECT 1 / 0;`n" + $openLine)
    $failure = Invoke-SyntheticSql $failedBeforeOpen 'lms_restore_failure_grant'
    $state = Get-SyntheticScalar "SELECT datallowconn::int FROM pg_database WHERE datname='lms_restore_failure_grant'"
    Assert-Synthetic ($failure.Code -ne 0 -and $state.Output -eq '0') 'Failure in ACL transaction leaves database closed'

    $failedAfterOpen = $sql.Replace($openLine, $openLine + "`nSELECT 1 / 0;")
    $failure = Invoke-SyntheticSql $failedAfterOpen 'lms_restore_failure_open'
    $state = Get-SyntheticScalar "SELECT datallowconn::int FROM pg_database WHERE datname='lms_restore_failure_open'"
    Assert-Synthetic ($failure.Code -ne 0 -and $state.Output -eq '0') 'Failure after ALTER rolls the database back to closed'

    $fixture = Get-SyntheticScalar "CREATE TABLE synthetic_marker (id integer PRIMARY KEY); INSERT INTO synthetic_marker VALUES (1)" 'lms_saas'
    Assert-Synthetic ($fixture.Code -eq 0) 'Synthetic dump fixture created'
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $restoreOutput = @(docker exec $scratchId sh -c 'set -o pipefail; pg_dump -Fc -U lms_user -d lms_saas | pg_restore --exit-on-error --no-owner --no-acl -U lms_user -d lms_restore_synthetic' 2>&1 | ForEach-Object { $_.ToString() })
        $restoreCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    Assert-Synthetic ($restoreCode -eq 0) 'Synthetic dump restored to the private target'
    $restored = Get-SyntheticScalar 'SELECT count(*) FROM synthetic_marker' 'lms_restore_synthetic'
    $runtimeConnection = Get-SyntheticScalar 'SELECT current_user' 'lms_restore_synthetic' 'lms_runtime'
    Assert-Synthetic ($restored.Code -eq 0 -and $restored.Output -eq '1' -and $runtimeConnection.Code -ne 0 -and $runtimeConnection.Output -match 'permission denied for database') 'Restore keeps synthetic data private from runtime'
    $collisionAfterRestore = Invoke-SyntheticSql $sql 'lms_restore_synthetic'
    $restoredAgain = Get-SyntheticScalar 'SELECT count(*) FROM synthetic_marker' 'lms_restore_synthetic'
    Assert-Synthetic ($collisionAfterRestore.Code -ne 0 -and $restoredAgain.Code -eq 0 -and $restoredAgain.Output -eq '1') 'An existing restored database and its data are preserved'

    $source = Get-SyntheticScalar "SELECT current_database() || '|' || has_database_privilege('lms_runtime',current_database(),'CONNECT')::int" 'lms_saas'
    $sourceAclAfter = Get-SyntheticScalar "SELECT COALESCE(datacl::text,'[NULL]') FROM pg_database WHERE datname='lms_saas'"
    Assert-Synthetic ($source.Code -eq 0 -and $source.Output -eq 'lms_saas|1' -and $sourceAclAfter.Code -eq 0 -and $sourceAclBefore.Output -ceq $sourceAclAfter.Output) 'Source database access and ACL remain unchanged'
} finally {
    if ($scratchId) {
        docker stop $scratchId | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Inspect synthetic container cleanup: $scratchName" }
    }
}

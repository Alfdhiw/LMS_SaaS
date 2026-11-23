# Disposable synthetic PostgreSQL only: never targets Compose or the application database.
param([string] $HbaSnapshotPath)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location $projectRoot
$fixture = Join-Path $projectRoot 'database/security/m1_pg_hba_scram_local.conf'
$rollbackScript = (Get-Content -LiteralPath 'scripts/m1-hba-rollback.sh' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$ownerScript = (Get-Content -LiteralPath 'scripts/m1-db-owner-command.sh' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$roleCheckScript = (Get-Content -LiteralPath 'scripts/m1-runtime-role-check.sh' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$grantsSql = (Get-Content -LiteralPath 'database/security/m1_audit_runtime_grants.sql' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$sql = (Get-Content -LiteralPath 'database/security/m1_create_private_database.sql' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$scratchName = 'lms-scram-lab-' + [guid]::NewGuid().ToString('N')
$scratchId = $null
$bootstrapId = $null
if ($HbaSnapshotPath) { $HbaSnapshotPath = (Resolve-Path -LiteralPath $HbaSnapshotPath).Path }

function Invoke-Lab {
    param([string] $Command, [string] $InputText = '')
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        # Windows PowerShell 5 passes embedded quotes to native commands in legacy mode.
        $nativeCommand = $Command
        if ($PSVersionTable.PSVersion.Major -lt 7) { $nativeCommand = $Command.Replace('"', '\"') }
        if ($InputText -ne '') {
            $output = @($InputText | docker exec -i -u postgres $scratchId sh -c $nativeCommand 2>&1 | ForEach-Object { $_.ToString() })
        } else {
            $output = @(docker exec -u postgres $scratchId sh -c $nativeCommand 2>&1 | ForEach-Object { $_.ToString() })
        }
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    return [pscustomobject]@{ Code = $code; Output = (($output -join "`n").Trim()) }
}

function Assert-Lab {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw "FAIL: $Message" }
    Write-Host "PASS: $Message"
}

function Invoke-LabOwner {
    param([string] $Script, [string[]] $Arguments = @(), [string] $InputText = '')
    if ($PSVersionTable.PSVersion.Major -lt 7) { $Script = $Script.Replace('"', '\"') }
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        if ($InputText -ne '') {
            $output = @($InputText | docker exec -i $scratchId sh -c $Script -- @Arguments 2>&1 | ForEach-Object { $_.ToString() })
        } else {
            $output = @(docker exec -i $scratchId sh -c $Script -- @Arguments 2>&1 | ForEach-Object { $_.ToString() })
        }
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    return [pscustomobject]@{ Code = $code; Output = (($output -join "`n").Trim()) }
}

function Invoke-WrapperLab {
    param([string] $Mode, [string] $SqlText = '')
    $dockerExecutable = (Get-Command docker -CommandType Application | Select-Object -First 1).Source
    # Mock discovery only; all actual exec calls are confined to this lab container.
    function docker {
        begin { $DockerArgs = @($args); $chunks = @() }
        process { if ($null -ne $_) { $chunks += $_ } }
        end {
            if ($DockerArgs.Count -ge 1 -and $DockerArgs[0] -eq 'compose') {
                $global:LASTEXITCODE = 0
                return $scratchId
            }
            if ($DockerArgs.Count -ge 1 -and $DockerArgs[0] -eq 'inspect') {
                if ($DockerArgs[-1] -ne $scratchId) { throw 'Unexpected synthetic inspection target.' }
                $global:LASTEXITCODE = 0
                return '{"com.docker.compose.project":"lms-saas-local","com.docker.compose.service":"db"}'
            }
            if ($DockerArgs.Count -lt 3 -or $DockerArgs[0] -ne 'exec' -or $DockerArgs[2] -ne $scratchId) {
                throw "Unexpected command or target in synthetic wrapper test (count=$($DockerArgs.Count), first=$($DockerArgs[0]))."
            }
            if ($DockerArgs[6] -ne '--') { $DockerArgs = @($DockerArgs[0..5]) + @('--') + @($DockerArgs[6..($DockerArgs.Count - 1)]) }
            $chunks | & $dockerExecutable @DockerArgs
        }
    }
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $wrapper = Join-Path $projectRoot 'scripts/m1-db-owner-command.ps1'
        switch ($Mode) {
            'private' { $SqlText | & $wrapper -Mode private -Target lms_aux_wrapper_lab -ExpectedContainerId $scratchId }
            'backup' { & $wrapper -Mode backup -BackupName lms_backup_wrapper.dump }
            'restore' { & $wrapper -Mode restore -BackupName lms_backup_wrapper.dump -Target lms_aux_wrapper_lab }
            default { throw 'Unsupported synthetic wrapper test.' }
        }
    } finally { $ErrorActionPreference = $savedPreference }
}

try {
    $scratchId = (docker run --detach --rm --pull never --name $scratchName --network none --add-host db:127.0.0.1 `
        --tmpfs '/var/lib/postgresql/data:rw,size=256m' -e POSTGRES_USER=lms_user `
        -e POSTGRES_PASSWORD=synthetic-scram-only -e POSTGRES_DB=lms_saas postgres:17-alpine | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $scratchId -notmatch '^[a-f0-9]{64}$') { $scratchId = $null; throw 'Unable to start isolated PostgreSQL.' }
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        docker exec $scratchId pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Isolated PostgreSQL was not ready.' }

    if ($HbaSnapshotPath) {
        docker cp $HbaSnapshotPath "${scratchId}:/tmp/lms_hba_rehearsal.conf"
        if ($LASTEXITCODE -ne 0) { throw 'Could not copy HBA snapshot to synthetic server.' }
        docker exec $scratchId chown postgres:postgres /tmp/lms_hba_rehearsal.conf
        if ($LASTEXITCODE -ne 0) { throw 'Could not prepare synthetic snapshot ownership.' }
        $rehearsal = Invoke-Lab 'cp /tmp/lms_hba_rehearsal.conf /var/lib/postgresql/data/pg_hba.conf && chmod 600 /var/lib/postgresql/data/pg_hba.conf && pg_ctl -D /var/lib/postgresql/data reload'
        if ($rehearsal.Code -ne 0) { throw 'Could not load snapshot on synthetic server.' }
        Start-Sleep -Seconds 1
    }

    $baseline = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    Assert-Lab ($baseline.Code -eq 0 -and $baseline.Output -eq 'lms_user') 'Synthetic baseline socket trust confirmed'
    $setup = Invoke-Lab 'psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas -c "CREATE ROLE lms_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD ''synthetic-runtime-only''; CREATE TABLE synthetic_marker (id integer PRIMARY KEY); INSERT INTO synthetic_marker VALUES (1);"'
    Assert-Lab ($setup.Code -eq 0) 'Synthetic runtime and dump fixture created'
    $backup = Invoke-Lab 'cp /var/lib/postgresql/data/pg_hba.conf /tmp/lms_hba_original.conf && chmod 600 /tmp/lms_hba_original.conf'
    Assert-Lab ($backup.Code -eq 0) 'Synthetic original HBA saved inside disposable container'
    docker cp $fixture "${scratchId}:/tmp/lab-hba-scram.conf"
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy synthetic HBA fixture.' }
    $reload = Invoke-Lab 'cp /tmp/lab-hba-scram.conf /var/lib/postgresql/data/pg_hba.conf && psql -X -U lms_user -d postgres -tAc "SELECT pg_reload_conf()"'
    Assert-Lab ($reload.Code -eq 0 -and $reload.Output -eq 't') 'Synthetic HBA reload requested'
    Start-Sleep -Seconds 1

    $none = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    $wrong = Invoke-Lab 'export PGPASSWORD=synthetic-wrong; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    $owner = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    Assert-Lab ($none.Code -ne 0 -and $wrong.Code -ne 0 -and $owner.Code -eq 0 -and $owner.Output -eq 'lms_user') 'Socket requires correct owner password'
    $noneTcp = Invoke-Lab 'unset PGPASSWORD; psql -X -w -h 127.0.0.1 -U lms_user -d postgres -tAc "SELECT current_user"'
    $ownerTcp = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -h 127.0.0.1 -U lms_user -d postgres -tAc "SELECT current_user"'
    Assert-Lab ($noneTcp.Code -ne 0 -and $ownerTcp.Code -eq 0 -and $ownerTcp.Output -eq 'lms_user') 'IPv4 loopback requires correct owner password'
    $noneIpv6 = Invoke-Lab 'unset PGPASSWORD; psql -X -w -h ::1 -U lms_user -d postgres -tAc "SELECT current_user"'
    $ownerIpv6 = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -h ::1 -U lms_user -d postgres -tAc "SELECT current_user"'
    Assert-Lab ($noneIpv6.Code -ne 0 -and $ownerIpv6.Code -eq 0 -and $ownerIpv6.Output -eq 'lms_user') 'IPv6 loopback requires correct owner password'
    $runtime = Invoke-Lab 'export PGPASSWORD=synthetic-runtime-only; psql -X -w -h 127.0.0.1 -U lms_runtime -d lms_saas -tAc "SELECT current_user"'
    $wrongRuntime = Invoke-Lab 'export PGPASSWORD=synthetic-wrong; psql -X -w -h 127.0.0.1 -U lms_runtime -d lms_saas -tAc "SELECT current_user"'
    Assert-Lab ($runtime.Code -eq 0 -and $runtime.Output -eq 'lms_runtime' -and $wrongRuntime.Code -ne 0) 'Synthetic runtime credential succeeds and incorrect credential fails'
    $hba = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL OR auth_method <> ''scram-sha-256''"'
    Assert-Lab ($hba.Code -eq 0 -and $hba.Output -eq '0') 'Synthetic HBA parses with only SCRAM rules'
    $ruleScope = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT count(*)::text || ''|'' || count(*) FILTER (WHERE ''replication'' = ANY(database) AND address = ''all'')::text FROM pg_hba_file_rules"'
    Assert-Lab ($ruleScope.Code -eq 0 -and $ruleScope.Output -eq '7|0') 'Candidate preserves seven rules without opening remote physical replication'
    $replication = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    $replicationWithoutPassword = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    $replicationWrongPassword = Invoke-Lab 'export PGPASSWORD=synthetic-wrong; psql -X -w -h 127.0.0.1 -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    $replicationTcp = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -h 127.0.0.1 -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    Assert-Lab ($replication.Code -eq 0 -and $replicationWithoutPassword.Code -ne 0 -and $replicationWrongPassword.Code -ne 0 -and $replicationTcp.Code -eq 0) 'Physical replication requires SCRAM on socket and IPv4 loopback'
    $replicationIpv6 = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -h ::1 -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    $replicationIpv6NoPassword = Invoke-Lab 'unset PGPASSWORD; psql -X -w -h ::1 -U lms_user -d "replication=true" -c "IDENTIFY_SYSTEM"'
    Assert-Lab ($replicationIpv6.Code -eq 0 -and $replicationIpv6NoPassword.Code -ne 0) 'Physical replication requires SCRAM on IPv6 loopback'
    $replicationRuntime = Invoke-Lab 'export PGPASSWORD=synthetic-runtime-only; psql -X -w -h 127.0.0.1 -U lms_runtime -d "replication=true" -c "IDENTIFY_SYSTEM"'
    Assert-Lab ($replicationRuntime.Code -ne 0 -and $replicationRuntime.Output -match 'replication') 'Runtime remains unable to start physical replication'
    $health = Invoke-Lab 'unset PGPASSWORD; pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas'
    Assert-Lab ($health.Code -eq 0) 'Readiness probe works without authenticating credentials'

    $missingSecret = Invoke-LabOwner $ownerScript @('private','lms_restore_missing_secret') $sql
    Assert-Lab ($missingSecret.Code -ne 0 -and $missingSecret.Output -match 'Owner secret is unavailable') 'Owner command fails closed without a secret'
    docker exec $scratchId mkdir -p /run/secrets
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare synthetic secret directory.' }
    docker cp (Join-Path $PSScriptRoot 'm1-synthetic-wrong-secret.txt') "${scratchId}:/run/secrets/postgres_owner_password"
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy wrong synthetic secret.' }
    $wrongSecret = Invoke-LabOwner $ownerScript @('private','lms_restore_wrong_secret') $sql
    Assert-Lab ($wrongSecret.Code -ne 0 -and $wrongSecret.Output -match 'password authentication failed') 'Owner command rejects an incorrect synthetic credential'
    docker cp (Join-Path $PSScriptRoot 'm1-synthetic-owner-secret.txt') "${scratchId}:/run/secrets/postgres_owner_password"
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy synthetic secret.' }
    $invalidTarget = Invoke-LabOwner $ownerScript @('private','lms_saas') $sql
    Assert-Lab ($invalidTarget.Code -ne 0 -and $invalidTarget.Output -match 'Invalid private database name') 'Owner command rejects application database target'
    $created = Invoke-LabOwner $ownerScript @('private','lms_restore_scram_lab') $sql
    Assert-Lab ($created.Code -eq 0) 'Actual owner command and private database SQL work using SCRAM'
    $wrapperResult = Invoke-WrapperLab 'private' $sql
    $wrapperState = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT count(*) FROM pg_database WHERE datname=''lms_aux_wrapper_lab'' AND datallowconn AND NOT has_database_privilege(''lms_runtime'',oid,''CONNECT'')"'
    Assert-Lab ($wrapperState.Code -eq 0 -and $wrapperState.Output -eq '1') 'PowerShell owner wrapper forwards SQL to the synthetic container'
    $fixtures = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d lms_saas -c "CREATE TABLE platform_audit_logs (id integer); CREATE TABLE tenant_audit_logs (id integer); CREATE TABLE migrations (id integer);"'
    $grants = Invoke-LabOwner $ownerScript @('grants') $grantsSql
    $grantCheck = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d lms_saas -tAc "SELECT has_table_privilege(''lms_runtime'',''platform_audit_logs'',''INSERT'')::int || ''|'' || has_table_privilege(''lms_runtime'',''platform_audit_logs'',''DELETE'')::int"'
    Assert-Lab ($fixtures.Code -eq 0 -and $grants.Code -eq 0 -and $grantCheck.Code -eq 0 -and $grantCheck.Output -eq '1|0') 'Actual grant command keeps synthetic audit append-only'
    $roleCheck = Invoke-LabOwner $roleCheckScript
    Assert-Lab ($roleCheck.Code -eq 0 -and $roleCheck.Output -eq '1') 'Actual provisioning preflight authenticates over SCRAM'
    $testDb = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -v ON_ERROR_STOP=1 -U lms_user -d postgres -c "CREATE DATABASE lms_saas_test"'
    $testMode = Invoke-LabOwner $ownerScript @('test') 'SELECT current_database();'
    Assert-Lab ($testDb.Code -eq 0 -and $testMode.Code -eq 0 -and $testMode.Output -match 'lms_saas_test') 'Owner test mode targets the dedicated test database under SCRAM'
    $backup = Invoke-LabOwner $ownerScript @('backup','lms_backup_synthetic.dump')
    $backupCollision = Invoke-LabOwner $ownerScript @('backup','lms_backup_synthetic.dump')
    Assert-Lab ($backup.Code -eq 0 -and $backupCollision.Code -ne 0) 'Owner backup uses SCRAM and refuses an existing filename'
    $wrapperBackup = Invoke-WrapperLab 'backup'
    $wrapperRestore = Invoke-WrapperLab 'restore'
    $wrapperRestored = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d lms_aux_wrapper_lab -tAc "SELECT count(*) FROM synthetic_marker"'
    Assert-Lab ($wrapperRestored.Code -eq 0 -and $wrapperRestored.Output -eq '1') 'PowerShell backup and restore wrapper works on synthetic data'
    $unsafeRestore = Invoke-LabOwner $ownerScript @('restore','lms_backup_synthetic.dump','lms_saas')
    Assert-Lab ($unsafeRestore.Code -ne 0) 'Owner restore refuses the application database'
    $restore = Invoke-LabOwner $ownerScript @('restore','lms_backup_synthetic.dump','lms_restore_scram_lab')
    $result = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d lms_restore_scram_lab -tAc "SELECT count(*) FROM synthetic_marker"'
    $denied = Invoke-Lab 'export PGPASSWORD=synthetic-runtime-only; psql -X -w -U lms_runtime -d lms_restore_scram_lab -tAc "SELECT current_user"'
    Assert-Lab ($restore.Code -eq 0 -and $result.Code -eq 0 -and $result.Output -eq '1' -and $denied.Code -ne 0 -and $denied.Output -match 'permission denied for database') 'Synthetic dump/restore succeeds and runtime remains denied'
    $repeatRestore = Invoke-LabOwner $ownerScript @('restore','lms_backup_synthetic.dump','lms_restore_scram_lab')
    $resultAgain = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d lms_restore_scram_lab -tAc "SELECT count(*) FROM synthetic_marker"'
    Assert-Lab ($repeatRestore.Code -ne 0 -and $resultAgain.Code -eq 0 -and $resultAgain.Output -eq '1') 'Restore refuses a nonempty database and preserves its data'

    $originalHash = Invoke-Lab 'sha256sum /tmp/lms_hba_original.conf'
    $currentHash = Invoke-Lab 'sha256sum /var/lib/postgresql/data/pg_hba.conf'
    if ($originalHash.Code -ne 0 -or $currentHash.Code -ne 0) { throw 'Could not hash synthetic HBA.' }
    $originalSha = $originalHash.Output.Split(' ')[0]
    $currentSha = $currentHash.Output.Split(' ')[0]
    if ($HbaSnapshotPath) {
        Assert-Lab ($originalSha -eq (Get-FileHash -LiteralPath $HbaSnapshotPath -Algorithm SHA256).Hash.ToLowerInvariant()) 'Rehearsal uses the exact saved local HBA snapshot'
    }
    if ($PSVersionTable.PSVersion.Major -lt 7) { $rollbackScript = $rollbackScript.Replace('"', '\"') }
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $badHashOutput = @(docker exec -u postgres $scratchId sh -c $rollbackScript -- /tmp/lms_hba_original.conf ('0' * 64) $currentSha 2>&1 | ForEach-Object { $_.ToString() })
        $badHashCode = $LASTEXITCODE
        $driftOutput = @(docker exec -u postgres $scratchId sh -c $rollbackScript -- /tmp/lms_hba_original.conf $originalSha ('0' * 64) 2>&1 | ForEach-Object { $_.ToString() })
        $driftCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    $afterRejection = Invoke-Lab 'sha256sum /var/lib/postgresql/data/pg_hba.conf'
    Assert-Lab ($badHashCode -ne 0 -and $driftCode -ne 0 -and $afterRejection.Output -eq $currentHash.Output) 'Rollback rejects corrupt backup and changed HBA without modifying configuration'
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $rollbackOutput = @(docker exec -u postgres $scratchId sh -c $rollbackScript -- /tmp/lms_hba_original.conf $originalSha $currentSha 2>&1 | ForEach-Object { $_.ToString() })
        $rollbackCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    Assert-Lab ($rollbackCode -eq 0) 'Actual rollback script restores HBA and reloads without database credentials'
    Start-Sleep -Seconds 1
    $restoredHash = Invoke-Lab 'sha256sum /var/lib/postgresql/data/pg_hba.conf'
    $restoredMode = Invoke-Lab 'stat -c %a:%U:%G /var/lib/postgresql/data/pg_hba.conf'
    Assert-Lab ($restoredHash.Output.Split(' ')[0] -eq $originalSha -and $restoredMode.Output -eq '600:postgres:postgres') 'Rollback restores exact bytes and postgres ownership with mode 600'
    $after = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    if ($after.Code -ne 0 -or $after.Output -ne 'lms_user') {
        $rules = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT line_number, type, auth_method, error FROM pg_hba_file_rules"'
        Write-Host "Rollback diagnostic: code=$($after.Code), output=$($after.Output); rules=$($rules.Output)"
    }
    Assert-Lab ($after.Code -eq 0 -and $after.Output -eq 'lms_user') 'Synthetic rollback restores socket baseline'

    # A second empty cluster tests the real init script with SCRAM from initdb.
    $initScript = (Resolve-Path -LiteralPath 'docker/postgres/init/01-create-test-db.sh').Path
    $bootstrapName = 'lms-scram-bootstrap-' + [guid]::NewGuid().ToString('N')
    $bootstrapId = (docker run --detach --rm --pull never --name $bootstrapName --network none `
        --tmpfs '/var/lib/postgresql/data:rw,size=256m' -e POSTGRES_USER=lms_user `
        -e POSTGRES_PASSWORD=synthetic-bootstrap-only -e POSTGRES_DB=lms_saas `
        -e 'POSTGRES_INITDB_ARGS=--auth-local=scram-sha-256 --auth-host=scram-sha-256' `
        --mount "type=bind,source=$initScript,target=/docker-entrypoint-initdb.d/01-create-test-db.sh,readonly" `
        postgres:17-alpine | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $bootstrapId -notmatch '^[a-f0-9]{64}$') { $bootstrapId = $null; throw 'Unable to start synthetic SCRAM bootstrap.' }
    $bootstrapReady = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        docker exec $bootstrapId pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas
        if ($LASTEXITCODE -eq 0) { $bootstrapReady = $true; break }
        Start-Sleep -Milliseconds 500
    }
    Assert-Lab $bootstrapReady 'Fresh synthetic SCRAM cluster and project init script start'
    $scratchIdOriginal = $scratchId
    try {
        $scratchId = $bootstrapId
        $initResult = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT count(*) FROM pg_database WHERE datname = ''lms_saas_test''"'
        $initNoPassword = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    } finally { $scratchId = $scratchIdOriginal }
    Assert-Lab ($initResult.Code -eq 0 -and $initResult.Output -eq '1' -and $initNoPassword.Code -ne 0) 'Actual test-database init succeeds with SCRAM and no-password login fails'
} finally {
    if ($bootstrapId) {
        docker stop $bootstrapId | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Inspect synthetic container cleanup: $bootstrapName" }
    }
    if ($scratchId) {
        docker stop $scratchId | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Inspect synthetic container cleanup: $scratchName" }
    }
}

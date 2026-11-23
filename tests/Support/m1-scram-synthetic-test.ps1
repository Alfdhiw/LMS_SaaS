# Disposable synthetic PostgreSQL only: never targets Compose or the application database.
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location $projectRoot
$fixture = Join-Path $PSScriptRoot 'm1-hba-scram-synthetic.conf'
$sql = (Get-Content -LiteralPath 'database/security/m1_create_private_database.sql' -Raw -Encoding UTF8).Replace("`r`n", "`n")
$scratchName = 'lms-scram-lab-' + [guid]::NewGuid().ToString('N')
$scratchId = $null
$bootstrapId = $null

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

try {
    $scratchId = (docker run --detach --rm --pull never --name $scratchName --network none `
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

    $baseline = Invoke-Lab 'unset PGPASSWORD; psql -X -w -U lms_user -d postgres -tAc "SELECT current_user"'
    Assert-Lab ($baseline.Code -eq 0 -and $baseline.Output -eq 'lms_user') 'Synthetic baseline socket trust confirmed'
    $setup = Invoke-Lab 'psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas -c "CREATE ROLE lms_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD ''synthetic-runtime-only''; CREATE TABLE synthetic_marker (id integer PRIMARY KEY); INSERT INTO synthetic_marker VALUES (1);"'
    Assert-Lab ($setup.Code -eq 0) 'Synthetic runtime and dump fixture created'
    $backup = Invoke-Lab 'cp /var/lib/postgresql/data/pg_hba.conf /tmp/lab-hba-original.conf'
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
    $runtime = Invoke-Lab 'export PGPASSWORD=synthetic-runtime-only; psql -X -w -h 127.0.0.1 -U lms_runtime -d lms_saas -tAc "SELECT current_user"'
    $wrongRuntime = Invoke-Lab 'export PGPASSWORD=synthetic-wrong; psql -X -w -h 127.0.0.1 -U lms_runtime -d lms_saas -tAc "SELECT current_user"'
    Assert-Lab ($runtime.Code -eq 0 -and $runtime.Output -eq 'lms_runtime' -and $wrongRuntime.Code -ne 0) 'Synthetic runtime credential succeeds and incorrect credential fails'
    $hba = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d postgres -tAc "SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL OR auth_method <> ''scram-sha-256''"'
    Assert-Lab ($hba.Code -eq 0 -and $hba.Output -eq '0') 'Synthetic HBA parses with only SCRAM rules'
    $health = Invoke-Lab 'unset PGPASSWORD; pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas'
    Assert-Lab ($health.Code -eq 0) 'Readiness probe works without authenticating credentials'

    $created = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -X -w -v ON_ERROR_STOP=1 -v target_db=lms_restore_scram_lab -U lms_user -d postgres' $sql
    Assert-Lab ($created.Code -eq 0) 'Checked-in private database SQL works using owner SCRAM'
    $restore = Invoke-Lab 'set -o pipefail; export PGPASSWORD="$POSTGRES_PASSWORD"; pg_dump -Fc -U lms_user -d lms_saas | pg_restore --exit-on-error --no-owner --no-acl -U lms_user -d lms_restore_scram_lab'
    $result = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -X -w -U lms_user -d lms_restore_scram_lab -tAc "SELECT count(*) FROM synthetic_marker"'
    $denied = Invoke-Lab 'export PGPASSWORD=synthetic-runtime-only; psql -X -w -U lms_runtime -d lms_restore_scram_lab -tAc "SELECT current_user"'
    Assert-Lab ($restore.Code -eq 0 -and $result.Code -eq 0 -and $result.Output -eq '1' -and $denied.Code -ne 0 -and $denied.Output -match 'permission denied for database') 'Synthetic dump/restore succeeds and runtime remains denied'

    $rollback = Invoke-Lab 'export PGPASSWORD="$POSTGRES_PASSWORD"; cp /tmp/lab-hba-original.conf /var/lib/postgresql/data/pg_hba.conf && psql -X -w -U lms_user -d postgres -tAc "SELECT pg_reload_conf()"'
    Assert-Lab ($rollback.Code -eq 0 -and $rollback.Output -eq 't') 'Synthetic original HBA rollback requested'
    Start-Sleep -Seconds 1
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

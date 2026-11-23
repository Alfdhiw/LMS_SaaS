$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
Set-Location $projectRoot

# A unique network and tmpfs-only PostgreSQL instance isolate this test from
# the existing database, role cluster, user secrets, and application storage.
$suffix = [guid]::NewGuid().ToString('N')
$networkName = "lms-runtime-test-$suffix"
$containerName = "lms-runtime-test-db-$suffix"
$marker = [guid]::NewGuid().ToString('N')
$fixtureEnv = Join-Path $projectRoot 'tests/Support/m1-runtime-synthetic.env'
$networkId = $null
$dbId = $null

if (Test-Path -LiteralPath 'bootstrap/cache/config.php') {
    throw 'Config cache aktif; uji terisolasi dibatalkan agar konfigurasi test tidak tertimpa.'
}
foreach ($image in @('postgres:17-alpine', 'lms-saas-php:8.4-local')) {
    docker image inspect $image --format '{{.Id}}' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Image $image tidak tersedia; uji dibatalkan." }
}

try {
    $networkId = (docker network create --internal $networkName | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $networkId -notmatch '^[a-f0-9]{64}$') {
        $networkId = $null
        throw 'Jaringan test terisolasi tidak dapat dibuat.'
    }
    $dbId = (docker run --detach --rm --pull never --name $containerName `
        --network $networkId --network-alias db --tmpfs '/var/lib/postgresql/data:rw,size=256m' `
        -e POSTGRES_USER=lms_user -e POSTGRES_PASSWORD=synthetic-owner-only `
        -e POSTGRES_DB=lms_saas_test postgres:17-alpine | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $dbId -notmatch '^[a-f0-9]{64}$') {
        $dbId = $null
        throw 'PostgreSQL test sementara tidak dapat dibuat.'
    }

    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        # PostgreSQL's temporary init server listens only on a local socket;
        # wait for the final TCP server rather than the transient init phase.
        docker exec $dbId pg_isready -q -h 127.0.0.1 -U lms_user -d lms_saas_test
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'PostgreSQL test sementara tidak siap dalam 30 detik.' }

    # The generated marker is only an isolation nonce, not a credential.
    docker exec $dbId psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas_test `
        -c "ALTER DATABASE lms_saas_test SET m1.runtime_test_marker = '$marker'"
    if ($LASTEXITCODE -ne 0) { throw 'Penanda isolasi database test gagal dibuat.' }

    docker run --rm --pull never --network $networkId `
        --mount "type=bind,src=$projectRoot,dst=/var/www/html,readonly" `
        --mount "type=bind,src=$fixtureEnv,dst=/var/www/html/.env,readonly" `
        --tmpfs '/var/www/html/storage:rw,size=64m' --workdir /var/www/html `
        -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= `
        -e DB_CONNECTION=pgsql -e DB_HOST=db -e DB_DATABASE=lms_saas_test `
        -e DB_USERNAME=lms_user -e DB_PASSWORD=synthetic-owner-only `
        -e M1_RUNTIME_INTEGRATION=1 -e "M1_RUNTIME_TEST_MARKER=$marker" `
        --entrypoint sh lms-saas-php:8.4-local -c `
        'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && exec ./vendor/bin/phpunit --do-not-cache-result tests/Runtime/M1RuntimeRoleIntegrationTest.php'
    if ($LASTEXITCODE -ne 0) { throw "Uji role runtime gagal dengan exit code $LASTEXITCODE." }
} finally {
    if ($dbId) {
        docker stop $dbId | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Kontainer test $containerName perlu diperiksa untuk pembersihan." }
    }
    if ($networkId) {
        docker network rm $networkId | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Jaringan test $networkName perlu diperiksa untuk pembersihan." }
    }
}

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker CLI tidak ditemukan. Instal Docker Desktop terlebih dahulu.' }
docker info | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker Engine tidak aktif. Jalankan Docker Desktop.' }

if (-not (Test-Path '.env')) {
    Copy-Item '.env.m0.example' '.env'
    Write-Host '[M0] .env dibuat dari template lokal; sesuaikan DB_PASSWORD jika perlu.'
}

docker compose -f docker-compose.yml config --quiet
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi Docker Compose tidak valid.' }

$buildLogPath = Join-Path ([System.IO.Path]::GetTempPath()) "lms-saas-docker-build-$PID.log"
$buildErrorActionPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    docker compose --progress=plain build app 2>&1 |
        ForEach-Object { $_.ToString() } |
        Tee-Object -FilePath $buildLogPath
    $buildExitCode = $LASTEXITCODE
}
finally {
    $ErrorActionPreference = $buildErrorActionPreference
}
if ($buildExitCode -ne 0) {
    Write-Host ''
    Write-Host "Docker build gagal dengan exit code $buildExitCode." -ForegroundColor Red
    Write-Host "Output detail Docker tersimpan di: $buildLogPath" -ForegroundColor Yellow
    throw "Docker build gagal. Lihat output Docker di atas atau log: $buildLogPath"
}
Remove-Item -LiteralPath $buildLogPath -Force -ErrorAction SilentlyContinue

docker compose up -d db redis mailpit
if ($LASTEXITCODE -ne 0) { throw 'Service dependensi gagal berjalan.' }

docker compose run --rm --no-deps --entrypoint sh app scripts/bootstrap-laravel.sh
if ($LASTEXITCODE -ne 0) { throw 'Bootstrap Laravel gagal.' }

docker compose up -d app web worker scheduler
if ($LASTEXITCODE -ne 0) { throw 'Service Laravel gagal berjalan.' }

docker compose exec -T app php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Migrasi baseline Laravel gagal.' }

docker compose exec -T app php artisan test --filter=M0
if ($LASTEXITCODE -ne 0) { throw 'M0 smoke test gagal.' }

Write-Host ''
Write-Host '[M0] Setup selesai. Buka http://localhost:8080 dan http://localhost:8025'
Write-Host '[M0] Tidak ada fitur tenant/auth pada milestone ini.'

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
docker compose ps
if ($LASTEXITCODE -ne 0) { throw 'Docker compose status gagal.' }
docker compose exec -T app php artisan --version
if ($LASTEXITCODE -ne 0) { throw 'Laravel tidak dapat diakses.' }
if (Select-String -Quiet -Path '.env' -Pattern '^DB_USERNAME=lms_runtime\s*$') {
    & "$PSScriptRoot/m1-operator.ps1" test-m0
} else {
    docker compose exec -T app php artisan test --filter=M0
    if ($LASTEXITCODE -ne 0) { throw 'Smoke test gagal.' }
}
docker compose exec -T app ./vendor/bin/pint --test
if ($LASTEXITCODE -ne 0) { throw 'Pint style check gagal. Jalankan Pint lalu ulangi.' }

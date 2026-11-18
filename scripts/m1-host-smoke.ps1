[CmdletBinding()]
param(
    [string[]] $TenantHosts = @(),
    [int] $Port = 8080
)

$ErrorActionPreference = 'Stop'

function Test-HostRoute {
    param(
        [Parameter(Mandatory)] [string] $HostName,
        [Parameter(Mandatory)] [string] $Path,
        [Parameter(Mandatory)] [int] $ExpectedStatus
    )

    $url = "http://$HostName`:$Port$Path"
    $resolve = "$HostName`:$Port`:127.0.0.1"
    $status = (& curl.exe --silent --show-error --output NUL --write-out '%{http_code}' --resolve $resolve $url).Trim()

    if ($LASTEXITCODE -ne 0) {
        throw "HTTP smoke gagal menghubungi $url."
    }

    if ([int]$status -ne $ExpectedStatus) {
        throw "HTTP smoke $url menghasilkan $status; expected $ExpectedStatus."
    }

    Write-Host "[PASS] $HostName$Path -> $status"
}

Test-HostRoute -HostName 'admin.platform.test' -Path '/login' -ExpectedStatus 200
Test-HostRoute -HostName 'unknown.platform.test' -Path '/login' -ExpectedStatus 404
Test-HostRoute -HostName '127.0.0.1' -Path '/' -ExpectedStatus 404

if ($TenantHosts.Count -eq 0) {
    $tenantOutput = docker compose exec -T app php artisan m1:smoke-hosts --limit=3
    if ($LASTEXITCODE -ne 0) {
        throw 'Tidak dapat membaca host tenant aktif dari container app.'
    }

    $TenantHosts = @($tenantOutput | Where-Object { $_ -match '\.platform\.test$' })
}

if ($TenantHosts.Count -eq 0) {
    Write-Warning 'Tidak ada tenant aktif sintetis; pemeriksaan central/unknown/loopback tetap lulus.'
    exit 0
}

foreach ($tenantHost in $TenantHosts | Select-Object -First 3) {
    Test-HostRoute -HostName $tenantHost.Trim() -Path '/login' -ExpectedStatus 200
}

Write-Host 'M1 host smoke selesai.'

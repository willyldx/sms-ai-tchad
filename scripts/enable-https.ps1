param(
    [Parameter(Mandatory = $true)]
    [string]$Domain,

    [Parameter(Mandatory = $true)]
    [string]$Email
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$nginxConf = Join-Path $projectRoot "infra/nginx/default.conf"
$tlsTemplate = Join-Path $projectRoot "infra/nginx/default.tls.conf"
$composeFile = Join-Path $projectRoot "docker-compose.yml"

if (-not (Test-Path $tlsTemplate)) {
    throw "TLS template not found: $tlsTemplate"
}

Write-Host "[1/6] Ensure stack is running..."
docker compose -f $composeFile up -d nginx certbot

Write-Host "[2/6] Request Let's Encrypt certificate for $Domain ..."
docker compose -f $composeFile run --rm certbot certonly --webroot -w /var/www/certbot -d $Domain --email $Email --agree-tos --no-eff-email

Write-Host "[3/6] Switch Nginx config to TLS template..."
$content = Get-Content -Raw $tlsTemplate
$content = $content.Replace("__DOMAIN__", $Domain)
Set-Content -Path $nginxConf -Value $content -Encoding UTF8

Write-Host "[4/6] Reload Nginx..."
docker compose -f $composeFile restart nginx

Write-Host "[5/6] Verify HTTPS endpoint..."
try {
    $response = Invoke-WebRequest -Uri "https://$Domain/health" -UseBasicParsing -TimeoutSec 20
    if ($response.StatusCode -ne 200) {
        throw "Unexpected status code $($response.StatusCode)"
    }
} catch {
    throw "HTTPS verification failed: $($_.Exception.Message)"
}

Write-Host "[6/6] HTTPS enabled successfully for $Domain"

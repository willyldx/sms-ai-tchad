Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $projectRoot "docker-compose.yml"

Write-Host "Renewing certificates with certbot..."
docker compose -f $composeFile run --rm certbot renew

Write-Host "Reloading Nginx..."
docker compose -f $composeFile restart nginx

Write-Host "Certificate renewal finished."

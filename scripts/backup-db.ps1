param(
    [string]$BackupDir = "./backups"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $projectRoot "docker-compose.yml"
$outputDir = Join-Path $projectRoot $BackupDir

if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$dumpFile = Join-Path $outputDir "sms_ai-$timestamp.sql"

Write-Host "Creating MariaDB dump: $dumpFile"
docker compose -f $composeFile exec -T db sh -lc 'exec mysqldump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > $dumpFile

Write-Host "Backup done."

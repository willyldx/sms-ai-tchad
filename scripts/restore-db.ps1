param(
    [Parameter(Mandatory = $true)]
    [string]$DumpFile
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $projectRoot "docker-compose.yml"
$dumpPath = Resolve-Path $DumpFile

if (-not (Test-Path $dumpPath)) {
    throw "Dump file not found: $DumpFile"
}

Write-Host "Restoring database from: $dumpPath"
Get-Content -Raw $dumpPath | docker compose -f $composeFile exec -T db sh -lc 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'

Write-Host "Restore done."

# Builds the installable WordPress plugin ZIP (upload via Plugins -> Add New -> Upload).
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$src = Join-Path $root 'plugin\amper-live-assisted-sales'
$dist = Join-Path $root 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$zip = Join-Path $dist 'amper-live-assisted-sales.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path $src -DestinationPath $zip
Write-Host "Built: $zip"

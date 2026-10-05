$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$Config = Join-Path $Root '.runtime/mysql.ini'
$Server = Join-Path $Root '.runtime/mysql-8.4.11-winx64/bin/mysqld.exe'
if (-not (Test-Path $Config) -or -not (Test-Path $Server)) { throw 'Run the approved isolated setup first.' }
if (Test-Path (Join-Path $Root '.runtime/mysql-init.sql')) { throw 'Initial provisioning has not been sealed. Complete bootstrap before normal startup.' }
if (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue) { throw 'Port 3307 is already in use. No existing process was stopped.' }
& $Server "--defaults-file=$Config"
exit $LASTEXITCODE

param([switch]$IncludeMysql)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Root = Split-Path $PSScriptRoot -Parent
$Runtime = Join-Path $Root '.runtime'
New-Item -ItemType Directory -Force -Path $Runtime | Out-Null
$Identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $Runtime /inheritance:r /grant:r "${Identity}:(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict local runtime permissions.' }

function Install-VerifiedArchive {
    param([string]$Url, [string]$Archive, [string]$Algorithm, [string]$Hash, [string]$Destination, [string]$Executable)
    if (Test-Path $Executable) { return }
    if (-not (Test-Path $Archive)) {
        & curl.exe --fail --location --retry 3 --silent --show-error --output $Archive $Url
        if ($LASTEXITCODE -ne 0) { throw 'Runtime download failed; existing installations were not changed.' }
    }
    if ((Get-FileHash $Archive -Algorithm $Algorithm).Hash -ne $Hash) { throw "Archive integrity check failed: $Algorithm. Installation stopped." }
    Expand-Archive -LiteralPath $Archive -DestinationPath $Destination -Force
}

$Php = Join-Path $Runtime 'php/php.exe'
Install-VerifiedArchive -Url 'https://downloads.php.net/~windows/releases/archives/php-8.5.11-nts-Win32-vs17-x64.zip' -Archive (Join-Path $Runtime 'php-8.5.11.zip') -Algorithm SHA256 -Hash '0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043' -Destination (Join-Path $Runtime 'php') -Executable $Php
$Ini = Join-Path $Runtime 'php/php.ini'
if (-not (Test-Path $Ini)) {
    @'
extension_dir="ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=sodium
extension=zip
date.timezone=UTC
expose_php=Off
display_errors=Off
log_errors=On
memory_limit=256M
upload_max_filesize=10M
post_max_size=12M
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_samesite=Lax
'@ | Set-Content -LiteralPath $Ini -Encoding ASCII
}
$Composer = Join-Path $Runtime 'composer.phar'
if (-not (Test-Path $Composer)) {
    & curl.exe --fail --location --retry 3 --silent --show-error --output $Composer 'https://getcomposer.org/download/2.10.3/composer.phar'
    if ($LASTEXITCODE -ne 0) { throw 'Composer download failed.' }
}
if ((Get-FileHash $Composer -Algorithm SHA256).Hash -ne '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6') { throw 'Composer integrity check failed.' }
& $Php -v
if ($LASTEXITCODE -ne 0) { throw 'Portable PHP failed. Check the required Visual C++ runtime without replacing XAMPP.' }
& $Php $Composer --version
if ($LASTEXITCODE -ne 0) { throw 'Portable Composer failed.' }
if ($IncludeMysql) {
    $Mysql = Join-Path $Runtime 'mysql-8.4.11-winx64/bin/mysqld.exe'
    Install-VerifiedArchive -Url 'https://dev.mysql.com/get/Downloads/MySQL-8.4/mysql-8.4.11-winx64.zip' -Archive (Join-Path $Runtime 'mysql-8.4.11.zip') -Algorithm MD5 -Hash '2e833921898a9a030ea6bfe81bd811bc' -Destination $Runtime -Executable $Mysql
    & $Mysql --version
    if ($LASTEXITCODE -ne 0) { throw 'Portable MySQL failed.' }
}
Write-Output 'Portable tools installed locally. PATH, Windows services and existing databases were not modified.'

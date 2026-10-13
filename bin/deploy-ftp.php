<?php

declare(strict_types=1);

/*
 * Deploys the project to the production server over FTP or explicit FTPS.
 *
 * A SHA-1 manifest of the deployable files is compared with the manifest
 * stored on the server by the previous run, so only changed files are
 * uploaded. Files that were deployed earlier but no longer exist locally
 * are deleted; files the script did not deploy (server .env, cPanel files,
 * storage contents) are never touched.
 *
 * Usage:
 *   php bin/deploy-ftp.php [--dry-run] [--yes] [--no-delete] [--verbose]
 *                          [--path=/remote/dir] [--limit=N]
 *
 * Credentials are read from .env.deploy (gitignored) or the environment:
 *   FTP_HOST, FTP_PORT, FTP_USER, FTP_PASSWORD, FTP_REMOTE_ROOT,
 *   FTP_SSL (explicit FTPS), FTP_PASSIVE, FTP_TIMEOUT
 */

$options = parseArguments($argv);
if ($options['help']) {
    printUsage();
    exit(0);
}

$root = dirname(__DIR__);
$config = require $root . '/config/deploy.php';
$env = loadEnvFile($root . '/.env.deploy');

$host = setting($env, 'FTP_HOST');
$user = setting($env, 'FTP_USER');
$password = setting($env, 'FTP_PASSWORD');
$port = (int) (setting($env, 'FTP_PORT') ?? '21');
$remoteRoot = rtrim($options['path'] ?? setting($env, 'FTP_REMOTE_ROOT') ?? '/', '/');
$remoteRoot = $remoteRoot === '' ? '/' : $remoteRoot;
$ssl = (setting($env, 'FTP_SSL') ?? '1') === '1';
$passive = (setting($env, 'FTP_PASSIVE') ?? '1') === '1';
$timeout = (int) (setting($env, 'FTP_TIMEOUT') ?? '30');

if ($host === null || $user === null || $password === null) {
    fail("FTP_HOST, FTP_USER and FTP_PASSWORD must be set in .env.deploy or the environment.\nSee .env.deploy.example.");
}
if (!extension_loaded('ftp')) {
    fail('The PHP ftp extension is not loaded. Enable extension=ftp in .runtime/php/php.ini.');
}

$localFiles = scanLocalFiles($root, $config['include'], $config['map']);
if ($localFiles === []) {
    fail('No deployable files found. Check config/deploy.php.');
}

$ftp = connect($host, $port, $user, $password, $ssl, $passive, $timeout);
printf("Connected to %s://%s:%d\n", $ssl ? 'ftps' : 'ftp', $host, $port);

$rootExists = @ftp_nlist($ftp, $remoteRoot) !== false;
$remoteManifest = fetchManifest($ftp, remotePath($remoteRoot, $config['manifest']));
$remoteFiles = $remoteManifest['files'] ?? [];
if ($remoteManifest === []) {
    echo "No remote manifest found: first deployment (nothing will be deleted).\n";
}

$uploads = [];
$uploadBytes = 0;
foreach ($localFiles as $path => $sha) {
    if (($remoteFiles[$path] ?? null) !== $sha) {
        $uploads[] = $path;
        $uploadBytes += filesize($root . '/' . manifestLocalSource($path, $config['map']));
    }
}

$deletions = $options['delete']
    ? array_values(array_filter(
        array_diff(array_keys($remoteFiles), array_keys($localFiles)),
        static fn (string $path): bool => !isProtected($path, $config['protected'])
    ))
    : [];

if ($options['limit'] !== null) {
    $uploads = array_slice($uploads, 0, $options['limit']);
}

printf(
    "Remote root : %s\nLocal files : %d\nTo upload   : %d (%s)\nTo delete   : %d%s\n",
    $remoteRoot,
    count($localFiles),
    count($uploads),
    formatBytes($uploadBytes),
    count($deletions),
    $options['delete'] ? '' : ' (deletions disabled)'
);

if ($options['verbose'] || $options['dry-run']) {
    foreach (array_slice($uploads, 0, 200) as $path) {
        echo "  upload  {$path}\n";
    }
    if (count($uploads) > 200) {
        echo '  ... and ' . (count($uploads) - 200) . " more\n";
    }
    foreach (array_slice($deletions, 0, 100) as $path) {
        echo "  delete  {$path}\n";
    }
}

if (!$rootExists) {
    echo "Remote root does not exist yet; it will be created.\n";
}
if ($options['dry-run']) {
    echo "Dry run: no changes made.\n";
    exit(0);
}
if ($uploads === [] && $deletions === []) {
    echo "Already up to date.\n";
    exit(0);
}
if (!$options['yes'] && !confirm('Proceed with deployment?')) {
    echo "Aborted.\n";
    exit(0);
}

$knownDirs = [];
ensureRemoteDir($ftp, $remoteRoot, $knownDirs);
if (!$rootExists && @ftp_nlist($ftp, $remoteRoot) === false) {
    fail("Remote directory {$remoteRoot} does not exist and could not be created.");
}
foreach ($config['ensure_dirs'] as $dir) {
    ensureRemoteDir($ftp, remotePath($remoteRoot, $dir), $knownDirs);
}

$failedUploads = [];
$done = 0;
foreach ($uploads as $path) {
    $local = $root . '/' . manifestLocalSource($path, $config['map']);
    $remoteDir = dirname($path);
    if ($remoteDir !== '.' && $remoteDir !== '') {
        ensureRemoteDir($ftp, remotePath($remoteRoot, $remoteDir), $knownDirs);
    }
    $remoteFile = remotePath($remoteRoot, $path);
    if (!@ftp_put($ftp, $remoteFile, $local, FTP_BINARY)
        && !@ftp_put($ftp, $remoteFile, $local, FTP_BINARY)) {
        $failedUploads[] = $path;
        fwrite(STDERR, "FAILED  {$path}\n");
        continue;
    }
    $done++;
    if ($options['verbose']) {
        echo "  uploaded {$path}\n";
    } elseif ($done % 50 === 0 || $done === count($uploads)) {
        printf("  uploaded %d/%d\n", $done, count($uploads));
    }
}

$failedDeletions = [];
$deletedDirs = [];
foreach ($deletions as $path) {
    if (!@ftp_delete($ftp, remotePath($remoteRoot, $path))) {
        $failedDeletions[] = $path;
        fwrite(STDERR, "FAILED delete {$path}\n");
        continue;
    }
    $deletedDirs[] = dirname($path);
    if ($options['verbose']) {
        echo "  deleted {$path}\n";
    }
}
foreach (array_unique($deletedDirs) as $dir) {
    for ($d = $dir; $d !== '.' && $d !== '' && $d !== '/'; $d = dirname($d)) {
        @ftp_rmdir($ftp, remotePath($remoteRoot, $d));
    }
}

$finalFiles = $localFiles;
foreach ($failedUploads as $path) {
    unset($finalFiles[$path]);
}
writeManifest($ftp, remotePath($remoteRoot, $config['manifest']), $finalFiles);
writeLocalManifest($root, $remoteRoot, $finalFiles);

printf(
    "Deployment %s: %d uploaded, %d deleted, %d failed.\n",
    ($failedUploads || $failedDeletions) ? 'completed with errors' : 'complete',
    $done,
    count($deletions) - count($failedDeletions),
    count($failedUploads) + count($failedDeletions)
);
exit(($failedUploads || $failedDeletions) ? 2 : 0);

function parseArguments(array $argv): array
{
    $options = [
        'dry-run' => false,
        'yes' => false,
        'delete' => true,
        'verbose' => false,
        'help' => false,
        'path' => null,
        'limit' => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        switch (true) {
            case $arg === '--dry-run':
                $options['dry-run'] = true;
                break;
            case $arg === '--yes' || $arg === '-y':
                $options['yes'] = true;
                break;
            case $arg === '--no-delete':
                $options['delete'] = false;
                break;
            case $arg === '--verbose' || $arg === '-v':
                $options['verbose'] = true;
                break;
            case $arg === '--help' || $arg === '-h':
                $options['help'] = true;
                break;
            case str_starts_with($arg, '--path='):
                $options['path'] = substr($arg, 7);
                break;
            case str_starts_with($arg, '--limit='):
                $options['limit'] = max(0, (int) substr($arg, 8));
                break;
            default:
                fail("Unknown option: {$arg}\nUse --help for usage.");
        }
    }
    return $options;
}

function printUsage(): void
{
    echo <<<'TXT'
Usage: php bin/deploy-ftp.php [options]

Options:
  --dry-run          Show what would be uploaded/deleted without changes
  --yes, -y          Skip the confirmation prompt
  --no-delete        Upload only; do not remove remote files
  --verbose, -v      List every file transferred
  --path=<dir>       Override FTP_REMOTE_ROOT
  --limit=<n>        Upload at most <n> files (for testing)
  --help, -h         Show this help

Configuration: .env.deploy (see .env.deploy.example) or environment variables.
Scope: config/deploy.php.

TXT;
}

function loadEnvFile(string $path): array
{
    $values = [];
    if (!is_file($path)) {
        return $values;
    }
    foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }
    return $values;
}

function setting(array $env, string $key): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return $env[$key] ?? null;
}

function scanLocalFiles(string $root, array $include, array $map): array
{
    $files = [];
    $skip = static function (string $name): bool {
        return $name === '.env'
            || str_starts_with($name, '.env.')
            || $name === 'Thumbs.db'
            || $name === '.DS_Store'
            || str_ends_with($name, '.log');
    };
    foreach ($include as $item) {
        $path = $root . '/' . $item;
        if (is_file($path)) {
            if (!$skip(basename($item))) {
                $files[$item] = sha1_file($path);
            }
            continue;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "Warning: {$item} not found, skipped.\n");
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $skip($file->getBasename())) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = sha1_file($file->getPathname());
        }
    }
    foreach ($map as $local => $remotePath) {
        if (!is_file($root . '/' . $local)) {
            fwrite(STDERR, "Warning: mapped file {$local} not found, skipped.\n");
            continue;
        }
        $files[$remotePath] = sha1_file($root . '/' . $local);
    }
    ksort($files);
    return $files;
}

function remotePath(string $root, string $path): string
{
    return ($root === '/' ? '' : $root) . '/' . ltrim($path, '/');
}

function manifestLocalSource(string $remotePath, array $map): string
{
    $source = array_search($remotePath, $map, true);
    return $source === false ? $remotePath : $source;
}

function connect(string $host, int $port, string $user, string $password, bool $ssl, bool $passive, int $timeout)
{
    $ftp = $ssl
        ? @ftp_ssl_connect($host, $port, $timeout)
        : @ftp_connect($host, $port, $timeout);
    if ($ftp === false) {
        fail(sprintf(
            'Could not connect to %s:%d via %s.',
            $host,
            $port,
            $ssl ? 'explicit FTPS' : 'plain FTP'
        ));
    }
    if (!@ftp_login($ftp, $user, $password)) {
        fail('FTP login failed. Check FTP_USER and FTP_PASSWORD.');
    }
    @ftp_set_option($ftp, FTP_TIMEOUT_SEC, $timeout);
    if ($passive && !@ftp_pasv($ftp, true)) {
        fwrite(STDERR, "Warning: could not enable passive mode.\n");
    }
    return $ftp;
}

function ensureRemoteDir($ftp, string $dir, array &$known): void
{
    $dir = rtrim($dir, '/');
    if ($dir === '' || $dir === '.' || isset($known[$dir])) {
        return;
    }
    $parent = dirname($dir);
    if ($parent !== $dir) {
        ensureRemoteDir($ftp, $parent, $known);
    }
    @ftp_mkdir($ftp, $dir);
    $known[$dir] = true;
}

function fetchManifest($ftp, string $remotePath): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'idep');
    if ($tmp === false) {
        return [];
    }
    $manifest = [];
    if (@ftp_get($ftp, $tmp, $remotePath, FTP_BINARY)) {
        $decoded = json_decode((string) file_get_contents($tmp), true);
        if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) {
            $manifest = $decoded;
        }
    }
    @unlink($tmp);
    return $manifest;
}

function writeManifest($ftp, string $remotePath, array $files): void
{
    $payload = json_encode([
        'version' => 1,
        'deployed_at' => gmdate('c'),
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam(sys_get_temp_dir(), 'idep');
    file_put_contents($tmp, $payload);
    if (!@ftp_put($ftp, $remotePath, $tmp, FTP_BINARY)) {
        fwrite(STDERR, "Warning: could not write remote manifest; next deploy will re-check all files.\n");
    }
    @unlink($tmp);
}

function writeLocalManifest(string $root, string $remoteRoot, array $files): void
{
    $dir = $root . '/storage/deploy';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/last-deploy.json', json_encode([
        'remote_root' => $remoteRoot,
        'deployed_at' => gmdate('c'),
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function isProtected(string $path, array $protected): bool
{
    foreach ($protected as $prefix) {
        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return true;
        }
    }
    return false;
}

function confirm(string $question): bool
{
    fwrite(STDOUT, $question . ' [y/N] ');
    $answer = strtolower(trim((string) fgets(STDIN)));
    return $answer === 'y' || $answer === 'yes';
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024.0 && $unit < count($units) - 1) {
        $size /= 1024.0;
        $unit++;
    }
    return $unit === 0 ? $bytes . ' B' : number_format($size, 1) . ' ' . $units[$unit];
}

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

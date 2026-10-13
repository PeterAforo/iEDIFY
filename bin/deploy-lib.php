<?php

declare(strict_types=1);

/*
 * Shared helpers for bin/deploy-ftp.php and bin/deploy-package.php.
 * Not intended to be run directly.
 */

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
    $tmp = tempnam(sys_get_temp_dir(), 'idep');
    file_put_contents($tmp, manifestPayload($files));
    if (!@ftp_put($ftp, $remotePath, $tmp, FTP_BINARY)) {
        fwrite(STDERR, "Warning: could not write remote manifest; next deploy will re-check all files.\n");
    }
    @unlink($tmp);
}

function manifestPayload(array $files): string
{
    return json_encode([
        'version' => 1,
        'deployed_at' => gmdate('c'),
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

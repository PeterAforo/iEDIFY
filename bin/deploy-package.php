<?php

declare(strict_types=1);

/*
 * One-shot package deployment for hosts that throttle per-file FTP work.
 *
 * Builds a ZIP of the deployable file set (config/deploy.php), uploads it
 * as a single file, then extracts it on the server through a short-lived,
 * token-gated PHP script invoked over HTTPS. Afterwards the remote
 * manifest is written so bin/deploy-ftp.php tracks future changes, and
 * files removed locally since the previous deploy are deleted.
 *
 * Usage:
 *   php bin/deploy-package.php [--yes] [--env=file] [--url=https://site]
 *                              [--verbose]
 *
 * Credentials come from .env.deploy (see deploy-ftp.php). DEPLOY_URL (or
 * --url) is the public HTTPS origin used to reach the extractor script.
 */

require __DIR__ . '/deploy-lib.php';

$options = ['yes' => false, 'env' => null, 'url' => null, 'verbose' => false, 'help' => false];
foreach (array_slice($argv, 1) as $arg) {
    switch (true) {
        case $arg === '--yes' || $arg === '-y':
            $options['yes'] = true;
            break;
        case $arg === '--verbose' || $arg === '-v':
            $options['verbose'] = true;
            break;
        case $arg === '--help' || $arg === '-h':
            $options['help'] = true;
            break;
        case str_starts_with($arg, '--env='):
            $options['env'] = substr($arg, 6);
            break;
        case str_starts_with($arg, '--url='):
            $options['url'] = substr($arg, 6);
            break;
        default:
            fail("Unknown option: {$arg}");
    }
}
if ($options['help']) {
    echo "Usage: php bin/deploy-package.php [--yes] [--env=file] [--url=origin] [--verbose]\n";
    exit(0);
}

$root = dirname(__DIR__);
$config = require $root . '/config/deploy.php';
$env = loadEnvFile($root . '/.env.deploy');

$host = setting($env, 'FTP_HOST');
$user = setting($env, 'FTP_USER');
$password = setting($env, 'FTP_PASSWORD');
$port = (int) (setting($env, 'FTP_PORT') ?? '21');
$remoteRoot = rtrim(setting($env, 'FTP_REMOTE_ROOT') ?? '/', '/');
$remoteRoot = $remoteRoot === '' ? '/' : $remoteRoot;
$ssl = (setting($env, 'FTP_SSL') ?? '1') === '1';
$passive = (setting($env, 'FTP_PASSIVE') ?? '1') === '1';
$timeout = (int) (setting($env, 'FTP_TIMEOUT') ?? '30');
$deployUrl = rtrim($options['url'] ?? setting($env, 'DEPLOY_URL') ?? '', '/');

if ($host === null || $user === null || $password === null) {
    fail('FTP_HOST, FTP_USER and FTP_PASSWORD must be set in .env.deploy.');
}
if (!filter_var($deployUrl, FILTER_VALIDATE_URL) || !str_starts_with($deployUrl, 'https://')) {
    fail('DEPLOY_URL (or --url) must be an https:// origin, e.g. https://iedifyafrica.com');
}
if ($options['env'] !== null && !is_file($root . '/' . $options['env'])) {
    fail("--env file not found: {$options['env']}");
}

$localFiles = scanLocalFiles($root, $config['include'], $config['map']);
if ($localFiles === []) {
    fail('No deployable files found. Check config/deploy.php.');
}
if (!isset($config['map']['deploy/htaccess.production'])) {
    fail('deploy/htaccess.production map entry is required so the extractor is reachable.');
}

// Build the package.
$zipPath = $root . '/storage/deploy/package.zip';
if (!is_dir(dirname($zipPath))) {
    @mkdir(dirname($zipPath), 0700, true);
}
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('Could not create package ZIP. Is ext-zip loaded?');
}
$totalBytes = 0;
foreach ($localFiles as $remoteFile => $sha) {
    $source = $root . '/' . manifestLocalSource($remoteFile, $config['map']);
    if (!$zip->addFile($source, $remoteFile)) {
        $zip->close();
        fail("Could not add {$remoteFile} to package.");
    }
    $totalBytes += filesize($source);
}
$zip->close();
printf(
    "Package: %d files, %s uncompressed, %s compressed\n",
    count($localFiles),
    formatBytes($totalBytes),
    formatBytes((int) filesize($zipPath))
);

if (!$options['yes'] && !confirm('Upload and extract on production?')) {
    echo "Aborted.\n";
    exit(0);
}

$ftp = connect($host, $port, $user, $password, $ssl, $passive, $timeout);
printf("Connected to %s://%s:%d\n", $ssl ? 'ftps' : 'ftp', $host, $port);

$remoteManifest = fetchManifest($ftp, remotePath($remoteRoot, $config['manifest']));
$remoteFiles = $remoteManifest['files'] ?? [];
$deletions = array_values(array_filter(
    array_diff(array_keys($remoteFiles), array_keys($localFiles)),
    static fn (string $path): bool => !isProtected($path, $config['protected'])
));
if ($remoteManifest === []) {
    echo "No remote manifest: treating this as a first deployment.\n";
}

// Upload the production .htaccess first so public/ is routable before the
// extractor is invoked, regardless of the current remote state.
$knownDirs = [];
ensureRemoteDir($ftp, $remoteRoot, $knownDirs);
if (!@ftp_put($ftp, remotePath($remoteRoot, '.htaccess'), $root . '/deploy/htaccess.production', FTP_BINARY)) {
    fail('Could not upload production .htaccess.');
}

$token = bin2hex(random_bytes(16));
$suffix = substr($token, 0, 8);
$zipRemote = ".deploy-pkg-{$suffix}.zip";
$extractRemote = "public/deploy-extract-{$suffix}.php";

$extractor = <<<PHP
<?php
if (!hash_equals('{$token}', (string) (\$_GET['key'] ?? ''))) {
    http_response_code(403);
    exit;
}
header('Content-Type: text/plain');
set_time_limit(300);
\$root = dirname(__DIR__);
\$zipFile = \$root . '/{$zipRemote}';
if (!is_file(\$zipFile)) {
    echo "package missing\n";
    exit(1);
}
\$zip = new ZipArchive();
if (\$zip->open(\$zipFile) !== true) {
    echo "package unreadable\n";
    exit(1);
}
\$total = \$zip->numFiles;
\$ok = \$zip->extractTo(\$root);
\$zip->close();
echo 'extracted=', \$ok ? \$total : 0, '/', \$total, "\n";
@unlink(\$zipFile);
@unlink(__FILE__);
PHP;

$extractorLocal = tempnam(sys_get_temp_dir(), 'idex');
file_put_contents($extractorLocal, $extractor);

echo "Uploading package...\n";
if (!@ftp_put($ftp, remotePath($remoteRoot, $zipRemote), $zipPath, FTP_BINARY)) {
    fail('Package upload failed.');
}
if (!@ftp_put($ftp, remotePath($remoteRoot, $extractRemote), $extractorLocal, FTP_BINARY)) {
    @ftp_delete($ftp, remotePath($remoteRoot, $zipRemote));
    fail('Extractor upload failed.');
}
@unlink($extractorLocal);

$extractUrl = $deployUrl . '/' . basename($extractRemote) . '?key=' . $token;
echo "Extracting on server via {$deployUrl}/" . basename($extractRemote) . "...\n";
$curl = curl_init($extractUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_CONNECTTIMEOUT => 20,
    // The local runtime has no CA bundle; the request only triggers the
    // extractor (which does a fixed, non-secret action), so CA pinning is
    // not worth carrying a cacert.pem for.
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($curl);
$curlError = curl_error($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

if ($response === false || $status !== 200) {
    @ftp_delete($ftp, remotePath($remoteRoot, $zipRemote));
    @ftp_delete($ftp, remotePath($remoteRoot, $extractRemote));
    fail("Extraction request failed (HTTP {$status}): {$curlError}");
}
echo trim((string) $response), "\n";
$extractOk = is_string($response)
    && preg_match('/extracted=(\d+)\/(\d+)/', $response, $m) === 1
    && $m[1] === $m[2]
    && (int) $m[2] === count($localFiles);
if (!$extractOk) {
    @ftp_delete($ftp, remotePath($remoteRoot, $zipRemote));
    @ftp_delete($ftp, remotePath($remoteRoot, $extractRemote));
    fail('Extraction did not complete; check server error log.');
}

// Extractor self-deletes, but clean up defensively.
@ftp_delete($ftp, remotePath($remoteRoot, $extractRemote));

// Files present in the previous manifest but gone locally are removed to
// keep the remote a mirror of the deployable set.
$failedDeletions = 0;
foreach ($deletions as $path) {
    if (!@ftp_delete($ftp, remotePath($remoteRoot, $path))) {
        $failedDeletions++;
        fwrite(STDERR, "FAILED delete {$path}\n");
    }
    if ($options['verbose']) {
        echo "  deleted {$path}\n";
    }
}

foreach ($config['ensure_dirs'] as $dir) {
    ensureRemoteDir($ftp, remotePath($remoteRoot, $dir), $knownDirs);
}

if ($options['env'] !== null) {
    $envFile = $root . '/' . $options['env'];
    if (@ftp_put($ftp, remotePath($remoteRoot, '.env'), $envFile, FTP_BINARY)) {
        echo "Uploaded {$options['env']} as remote .env (untracked).\n";
    } else {
        fwrite(STDERR, "FAILED  .env upload\n");
    }
}

writeManifest($ftp, remotePath($remoteRoot, $config['manifest']), $localFiles);
writeLocalManifest($root, $remoteRoot, $localFiles);

printf(
    "Deployment complete: %d files extracted, %d deleted%s.\n",
    count($localFiles),
    count($deletions) - $failedDeletions,
    $failedDeletions ? ", {$failedDeletions} deletion failures" : ''
);
exit($failedDeletions ? 2 : 0);

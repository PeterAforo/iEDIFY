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
 * For large first-time uploads on hosts that throttle FTP transfers,
 * bin/deploy-package.php (single archive + server-side extract) is much
 * faster.
 *
 * Usage:
 *   php bin/deploy-ftp.php [--dry-run] [--yes] [--no-delete] [--verbose]
 *                          [--path=/remote/dir] [--limit=N] [--env=file] [--jobs=N]
 *
 * Credentials are read from .env.deploy (gitignored) or the environment:
 *   FTP_HOST, FTP_PORT, FTP_USER, FTP_PASSWORD, FTP_REMOTE_ROOT,
 *   FTP_SSL (explicit FTPS), FTP_PASSIVE, FTP_TIMEOUT
 */

require __DIR__ . '/deploy-lib.php';

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
if ($options['env'] !== null && !is_file($root . '/' . $options['env'])) {
    fail("--env file not found: {$options['env']}");
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

// Pre-create every remote directory the uploads need so the transfer
// workers can start files without per-file mkdir round-trips.
$remoteDirs = [];
foreach ($config['ensure_dirs'] as $dir) {
    $remoteDirs[$dir] = true;
}
foreach ($uploads as $path) {
    $dir = dirname($path);
    if ($dir !== '.' && $dir !== '') {
        $remoteDirs[$dir] = true;
    }
}
$remoteDirs = array_keys($remoteDirs);
usort($remoteDirs, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
foreach ($remoteDirs as $dir) {
    ensureRemoteDir($ftp, remotePath($remoteRoot, $dir), $knownDirs);
}

// $manifestFiles tracks the believed remote state; it is flushed to the
// server every 250 operations so an interrupted deploy resumes instead of
// starting over.
$manifestFiles = $remoteFiles;
$manifestPath = remotePath($remoteRoot, $config['manifest']);
$opsSinceFlush = 0;
$flush = static function () use ($ftp, $manifestPath, &$manifestFiles, &$opsSinceFlush): void {
    writeManifest($ftp, $manifestPath, $manifestFiles);
    $opsSinceFlush = 0;
};

$failedUploads = [];
$done = 0;
$attempts = array_fill_keys($uploads, 0);
$queue = $uploads;
$inflight = [];

if ($queue !== []) {
    $workers = [];
    for ($i = 0; $i < $options['jobs']; $i++) {
        if ($i > 0) {
            usleep(400000);
        }
        $workers[] = connect($host, $port, $user, $password, $ssl, $passive, $timeout);
    }
    printf("Transfer pool: %d connections\n", count($workers));

    while ($queue !== [] || $inflight !== []) {
        foreach ($workers as $i => $conn) {
            if (isset($inflight[$i]) || $queue === []) {
                continue;
            }
            $path = $queue[0];
            $local = $root . '/' . manifestLocalSource($path, $config['map']);
            $started = @ftp_nb_put($conn, remotePath($remoteRoot, $path), $local, FTP_BINARY);
            if ($started === false || $started === FTP_FAILED) {
                if (++$attempts[$path] >= 3) {
                    array_shift($queue);
                    $failedUploads[] = $path;
                    fwrite(STDERR, "FAILED  {$path}\n");
                }
                continue;
            }
            array_shift($queue);
            if ($started === FTP_FINISHED) {
                $done++;
                $manifestFiles[$path] = $localFiles[$path];
                $opsSinceFlush++;
            } else {
                $inflight[$i] = $path;
            }
        }
        foreach ($inflight as $i => $path) {
            $result = @ftp_nb_continue($workers[$i]);
            if ($result === FTP_FINISHED) {
                unset($inflight[$i]);
                $done++;
                $manifestFiles[$path] = $localFiles[$path];
                $opsSinceFlush++;
                if ($options['verbose']) {
                    echo "  uploaded {$path}\n";
                } elseif ($done % 100 === 0 || $done === count($uploads)) {
                    printf("  uploaded %d/%d\n", $done, count($uploads));
                }
            } elseif ($result === FTP_FAILED) {
                unset($inflight[$i]);
                if (++$attempts[$path] >= 3) {
                    $failedUploads[] = $path;
                    fwrite(STDERR, "FAILED  {$path}\n");
                } else {
                    $queue[] = $path;
                }
            }
        }
        if ($opsSinceFlush >= 250) {
            $flush();
        }
        usleep(20000);
    }
    foreach ($workers as $conn) {
        @ftp_close($conn);
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
    unset($manifestFiles[$path]);
    $opsSinceFlush++;
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

if ($options['env'] !== null) {
    $envFile = $root . '/' . $options['env'];
    if (@ftp_put($ftp, remotePath($remoteRoot, '.env'), $envFile, FTP_BINARY)) {
        echo "Uploaded {$options['env']} as remote .env (untracked).\n";
    } else {
        fwrite(STDERR, "FAILED  .env upload\n");
        $failedUploads[] = '.env';
    }
}

$flush();
writeLocalManifest($root, $remoteRoot, $manifestFiles);

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
        'env' => null,
        'jobs' => 3,
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
            case str_starts_with($arg, '--env='):
                $options['env'] = substr($arg, 6);
                break;
            case str_starts_with($arg, '--jobs='):
                $options['jobs'] = max(1, min(8, (int) substr($arg, 7)));
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
  --env=<file>       Also upload <file> to the remote root as .env
                     (untracked; use for the initial production .env)
  --jobs=<n>         Parallel upload connections, 1-8 (default 3)
  --help, -h         Show this help

Configuration: .env.deploy (see .env.deploy.example) or environment variables.
Scope: config/deploy.php.

TXT;
}

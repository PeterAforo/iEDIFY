<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['app', 'bootstrap', 'config', 'database', 'public', 'bin', 'tests'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' && $file->getFilename() !== 'console') {
            continue;
        }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            $failed = true;
        }
    }
}
exit($failed ? 1 : 0);

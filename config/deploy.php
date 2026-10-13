<?php

declare(strict_types=1);

// Deployment scope for bin/deploy-ftp.php. Only the paths listed here are
// uploaded; everything else (tests, node_modules, .runtime, storage data,
// .env files, docs) stays local.
return [
    // Directories and individual files uploaded to FTP_REMOTE_ROOT.
    'include' => [
        'app',
        'bin',
        'bootstrap',
        'config',
        'database',
        'public',
        'resources',
        'vendor',
        'composer.json',
        'composer.lock',
        'phinx.php',
    ],
    // Local file => remote path overrides. The production document root
    // (public_html) must route requests into public/ while denying direct
    // access to application files.
    'map' => [
        'deploy/htaccess.production' => '.htaccess',
    ],
    // Remote directories created if missing but never synced or pruned.
    'ensure_dirs' => [
        'storage',
        'storage/cache',
        'storage/exports',
        'storage/logs',
        'storage/mail',
        'storage/private',
        'storage/quarantine',
        'storage/sessions',
    ],
    // Remote paths never deleted even if they end up in the manifest.
    'protected' => [
        '.well-known',
        'cgi-bin',
    ],
    // Name of the state file stored on the server to track deployments.
    'manifest' => '.iedify-deploy.json',
];

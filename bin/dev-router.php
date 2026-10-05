<?php

declare(strict_types=1);

$public = realpath(dirname(__DIR__) . '/public');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$resolved = str_contains($path, "\0") ? false : realpath($public . '/' . ltrim($path, '/'));
$publicPrefix = str_replace('\\', '/', $public . '/');
if ($resolved !== false && str_starts_with(str_replace('\\', '/', $resolved), $publicPrefix) && is_file($resolved)) {
    $relative = substr(str_replace('\\', '/', $resolved), strlen($publicPrefix));
    if (str_starts_with($relative, 'build/') && !preg_match('~(?:^|/)\.~', $relative) && in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'woff2', 'ico'], true)) {
        return false;
    }
}
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $public . '/index.php';

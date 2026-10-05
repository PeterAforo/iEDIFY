<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

try {
    $kernel = require dirname(__DIR__) . '/bootstrap/app.php';
    $kernel->handle(Request::createFromGlobals())->send();
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    echo 'The service is temporarily unavailable. Please try again later.';
}

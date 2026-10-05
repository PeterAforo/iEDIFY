<?php

declare(strict_types=1);

use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\View\View;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = Config::load($root);
$config->validate();
date_default_timezone_set('UTC');
$logger = new Logger('iedify');
$logger->pushHandler(new StreamHandler($root . '/storage/logs/application.log', Level::Warning, true, 0600));
$session = new Session(new NativeSessionStorage([
    'name' => 'iedify_session',
    'cookie_secure' => $config->boolean('SESSION_SECURE', true),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_lifetime' => 0,
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'gc_maxlifetime' => 1800,
], new NativeFileSessionHandler($root . '/storage/sessions')));
return new Kernel($config, new View($root), $session, $logger);

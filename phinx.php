<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;

$config = Config::load(__DIR__);
$environment = $config->environment();
if ($environment === 'test' && !preg_match('/_test$/D', $config->string('DB_DATABASE'))) {
    throw new RuntimeException('Test migrations require a dedicated database ending in _test.');
}
return [
    'paths' => ['migrations' => __DIR__ . '/database/migrations'],
    'environments' => [
        'default_migration_table' => 'schema_migrations',
        'default_environment' => $environment,
        $environment => ['name' => $config->string('DB_DATABASE'), 'connection' => Connection::open($config)],
    ],
    'version_order' => 'creation',
];

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows') {
    exit("This command provisions an isolated Windows development instance only.\n");
}
$root = dirname(__DIR__);
$runtime = $root . '/.runtime';
foreach (['/.env', '/.env.test', '/.runtime/mysql-data', '/.runtime/mysql-init.sql'] as $path) {
    if (file_exists($root . $path)) {
        fwrite(STDERR, "Local configuration or data already exists; refusing to overwrite it.\n");
        exit(1);
    }
}
foreach (['/mysql-data', '/mysql-logs'] as $path) {
    if (!mkdir($runtime . $path, 0700, true)) {
        throw new RuntimeException('Cannot create isolated local runtime directory.');
    }
}
foreach (['private', 'quarantine', 'logs', 'cache', 'exports', 'mail', 'sessions'] as $path) {
    if (!is_dir($root . '/storage/' . $path) && !mkdir($root . '/storage/' . $path, 0700, true)) {
        throw new RuntimeException('Cannot create private application directory.');
    }
}
$key = base64_encode(random_bytes(32));
$rootPassword = bin2hex(random_bytes(32));
$localPassword = bin2hex(random_bytes(32));
$testPassword = bin2hex(random_bytes(32));
$base = str_replace('\\', '/', $runtime);
file_put_contents($runtime . '/mysql.ini', "[mysqld]\nbasedir=\"{$base}/mysql-8.4.11-winx64\"\ndatadir=\"{$base}/mysql-data\"\nport=3307\nbind-address=127.0.0.1\nmysqlx=0\nskip-name-resolve\nlocal-infile=0\nlog-error=\"{$base}/mysql-logs/server.log\"\ncharacter-set-server=utf8mb4\ncollation-server=utf8mb4_0900_ai_ci\n");
$sql = [
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '{$rootPassword}';",
    'CREATE DATABASE iedify_local CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;',
    'CREATE DATABASE iedify_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;',
    "CREATE USER 'iedify_local'@'127.0.0.1' IDENTIFIED BY '{$localPassword}';",
    "CREATE USER 'iedify_test'@'127.0.0.1' IDENTIFIED BY '{$testPassword}';",
    "GRANT ALL PRIVILEGES ON iedify_local.* TO 'iedify_local'@'127.0.0.1';",
    "GRANT ALL PRIVILEGES ON iedify_test.* TO 'iedify_test'@'127.0.0.1';",
];
file_put_contents($runtime . '/mysql-init.sql', implode("\n", $sql) . "\n");
file_put_contents($runtime . '/mysql-admin.ini', "[client]\nuser=root\npassword={$rootPassword}\nhost=127.0.0.1\nport=3307\n");
foreach (['local' => $localPassword, 'test' => $testPassword] as $environment => $password) {
    $path = $root . ($environment === 'local' ? '/.env' : '/.env.test');
    file_put_contents($path, "APP_ENV={$environment}\nAPP_URL=http://127.0.0.1:8080\nAPP_KEY=\"{$key}\"\nDB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE=iedify_{$environment}\nDB_USERNAME=iedify_{$environment}\nDB_PASSWORD={$password}\nSESSION_SECURE=false\nMAIL_TRANSPORT=capture\nMAIL_LIVE_ENABLED=false\nSIGNUP_ENABLED=false\nFUNDING_ENABLED=false\nSMS_ENABLED=false\n");
}
fwrite(STDOUT, "Prepared isolated local/test databases and random credentials without displaying them. No existing database was accessed.\n");

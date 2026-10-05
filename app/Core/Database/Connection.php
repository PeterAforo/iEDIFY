<?php

declare(strict_types=1);

namespace IEdify\Core\Database;

use IEdify\Core\Config;
use PDO;
use RuntimeException;

final class Connection
{
    public static function open(Config $config): PDO
    {
        $host = $config->string('DB_HOST', '127.0.0.1');
        $name = $config->string('DB_DATABASE');
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new RuntimeException('Invalid database host or name configuration.');
        }
        $port = $config->integer('DB_PORT', 3306, 1, 65535);
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $config->string('DB_USERNAME'), $config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false || !preg_match('/^8\./', $version)) {
            throw new RuntimeException('This deployment requires MySQL 8.x; MariaDB is not a verified substitute.');
        }
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        return $pdo;
    }
}

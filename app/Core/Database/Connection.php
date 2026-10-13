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
        if (stripos($version, 'mariadb') !== false) {
            // Shared hosting supplies MariaDB instead of MySQL; 10.6+ covers
            // every feature used here (CHECK, JSON-as-storage, SKIP LOCKED).
            // The version sits before "-MariaDB", after any "5.5.5-" prefix.
            preg_match('/(\d+\.\d+(\.\d+)?)-MariaDB/i', $version, $match);
            $supported = isset($match[1]) && version_compare($match[1], '10.6', '>=');
        } else {
            $supported = preg_match('/^8\./', $version) === 1;
        }
        if (!$supported) {
            throw new RuntimeException('This deployment requires MySQL 8.x or MariaDB 10.6+.');
        }
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        return $pdo;
    }
}

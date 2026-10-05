<?php

declare(strict_types=1);

namespace IEdify\Services\Backup;

use PDO;

/**
 * Whole-database backup/restore without external binaries, so the same code
 * runs on Windows development and cPanel cron. Format is gzipped JSON lines:
 * a meta line, one schema line per table (SHOW CREATE TABLE), and chunked
 * row lines. restore() takes a table prefix; rehearsing with a prefix lets a
 * restore be verified in the live database without touching real tables.
 */
final readonly class BackupService
{
    private const CHUNK = 250;

    /** Write a full dump to $directory; returns the archive path. */
    public function create(PDO $pdo, string $directory): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Backup directory is unavailable.');
        }
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $path = rtrim($directory, '/') . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jsonl.gz';
        $lines = [json_encode(['type' => 'meta', 'created_at' => gmdate('c'), 'tables' => count($tables), 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn()], JSON_THROW_ON_ERROR)];
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
            $lines[] = json_encode(['type' => 'table', 'name' => $table, 'create' => $create[1]], JSON_THROW_ON_ERROR);
            $statement = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            $buffer = [];
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $buffer[] = $row;
                if (count($buffer) === self::CHUNK) {
                    $lines[] = json_encode(['type' => 'rows', 'table' => $table, 'rows' => $buffer], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $lines[] = json_encode(['type' => 'rows', 'table' => $table, 'rows' => $buffer], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }
        $encoded = gzencode(implode("\n", $lines) . "\n", 6);
        if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('The backup could not be written.');
        }
        return $path;
    }

    /** Replay a dump; $prefix is applied to table names (use 'rehearse_' for restore rehearsals, '' for real restores). */
    public function restore(PDO $pdo, string $path, string $prefix = ''): int
    {
        $contents = file_get_contents($path);
        if ($contents === false || ($decoded = gzdecode($contents)) === false) {
            throw new \RuntimeException('The backup file could not be read.');
        }
        // MySQL DDL implicitly commits, so a restore is staged rather than
        // transactional: rehearse with a table prefix and treat a real
        // restore as a maintenance-window operation per the runbook.
        $statements = 0;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0');
        try {
            foreach (explode("\n", $decoded) as $line) {
                if ($line === '') {
                    continue;
                }
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($entry['type'] === 'table') {
                    $create = preg_replace('/CREATE TABLE `([^`]+)`/', 'CREATE TABLE `' . $prefix . '$1`', $entry['create'], 1);
                    // Named constraints are schema-global; let InnoDB auto-name
                    // them so prefixed rehearsal copies cannot collide.
                    if ($prefix !== '') {
                        $create = preg_replace('/CONSTRAINT `[^`]+` /', '', (string) $create);
                    }
                    $pdo->exec('DROP TABLE IF EXISTS `' . $prefix . $entry['name'] . '`');
                    $pdo->exec((string) $create);
                    $statements++;
                } elseif ($entry['type'] === 'rows' && $entry['rows'] !== []) {
                    $quoted = [];
                    foreach ($entry['rows'] as $row) {
                        $quoted[] = '(' . implode(',', array_map(static fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), $row)) . ')';
                    }
                    // One multi-row INSERT per chunk keeps replays fast even
                    // with autocommit enabled.
                    $pdo->exec('INSERT INTO `' . $prefix . $entry['table'] . '` VALUES ' . implode(',', $quoted));
                    $statements += count($entry['rows']);
                }
            }
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1, UNIQUE_CHECKS = 1');
        }
        return $statements;
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Database;

use IEdify\Core\Config;
use PDO;
use Phinx\Config\Config as PhinxConfig;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class MigrationRunner
{
    public function __construct(private string $root, private Config $config)
    {
    }

    public function migrate(InputInterface $input, OutputInterface $output): void
    {
        $pdo = Connection::open($this->config);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lockName = 'iedify:migrations:' . $this->config->string('DB_DATABASE');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Another migration process owns the migration lock.');
        }
        try {
            $environment = $this->config->environment();
            $config = new PhinxConfig([
                'paths' => ['migrations' => $this->root . '/database/migrations'],
                'environments' => [
                    'default_migration_table' => 'schema_migrations',
                    'default_environment' => $environment,
                    $environment => ['name' => $this->config->string('DB_DATABASE'), 'connection' => $pdo],
                ],
            ]);
            (new Manager($config, $input, $output))->migrate($environment);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Services;

use IEdify\Core\Database\Transaction;
use PDO;

final readonly class RoleSeeder
{
    public function __construct(private PDO $pdo)
    {
    }

    public function seed(): void
    {
        $roles = require dirname(__DIR__, 4) . '/config/roles.php';
        (new Transaction($this->pdo))->run(function () use ($roles): void {
            foreach ($roles as $name => $role) {
                $this->pdo->prepare('INSERT INTO roles (name, privileged) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = name')->execute([$name, $role['privileged'] ? 1 : 0]);
                $query = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
                $query->execute([$name]);
                $roleId = $query->fetchColumn();
                foreach ($role['permissions'] as $permission) {
                    $this->pdo->prepare('INSERT INTO permissions (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name')->execute([$permission]);
                    $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ? ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$roleId, $permission]);
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Database;

use LogicException;
use PDO;
use Throwable;

final readonly class Transaction
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Nested transactions require an explicit aggregate boundary.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollbackIfActive();
            throw $error;
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

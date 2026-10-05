<?php

declare(strict_types=1);

namespace IEdify\Core\Jobs;

use LogicException;
use PDO;

final readonly class Outbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $eventKey, string $type, array $payload): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Outbox events must be written inside the business transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO outbox_events (event_key, event_type, payload, available_at, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE event_key = event_key');
        $statement->execute([$eventKey, $type, json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
}

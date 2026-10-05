<?php

declare(strict_types=1);

namespace IEdify\Core\Audit;

use LogicException;
use PDO;

final readonly class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(?int $actorId, string $action, string $entityType, string $entityId, array $metadata = []): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Business audit events require the same transaction as the mutation.');
        }
        $allowed = array_intersect_key($metadata, array_flip(['from_status', 'to_status', 'version', 'reason_code', 'request_id']));
        $statement = $this->pdo->prepare('INSERT INTO audit_events (actor_id, action, entity_type, entity_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $statement->execute([$actorId, $action, $entityType, $entityId, json_encode($allowed, JSON_THROW_ON_ERROR)]);
    }
}

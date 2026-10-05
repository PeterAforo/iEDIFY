<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Data-subject requests: account data export and deletion requests. Pending
 * requests are deduplicated; completed deletions deactivate the account
 * without erasing legitimate audit/financial history.
 */
final readonly class DataRequestService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function request(Actor $actor, string $type): void
    {
        if (!in_array($type, ['export', 'deletion'], true)) {
            throw new \InvalidArgumentException('Unsupported request type.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $type): void {
            $existing = $this->pdo->prepare("SELECT id FROM data_requests WHERE user_id = ? AND type = ? AND status = 'pending'");
            $existing->execute([$actor->id, $type]);
            if ($existing->fetchColumn() !== false) {
                throw new \InvalidArgumentException('You already have a pending request of this type.');
            }
            $this->pdo->prepare('INSERT INTO data_requests (user_id, type, requested_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$actor->id, $type]);
            (new AuditLog($this->pdo))->record($actor->id, 'data_request.' . $type, 'data_requests', (string) $this->pdo->lastInsertId());
        });
    }

    public function pending(): array
    {
        return $this->pdo->query("SELECT r.*, u.email, u.name FROM data_requests r JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' ORDER BY r.requested_at")->fetchAll();
    }

    public function decide(Actor $actor, int $id, string $decision): void
    {
        if (!(new Policy())->allows($actor, 'identity.manage')) {
            throw new HttpError(403, 'You do not have permission to handle data requests.');
        }
        if (!in_array($decision, ['completed', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unsupported decision.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $decision): void {
            $statement = $this->pdo->prepare("SELECT * FROM data_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
            $statement->execute([$id]);
            $request = $statement->fetch();
            if ($request === false) {
                throw new HttpError(404, 'This request was not found or is already decided.');
            }
            $this->pdo->prepare('UPDATE data_requests SET status = ?, decided_at = UTC_TIMESTAMP(6), decided_by = ? WHERE id = ?')->execute([$decision, $actor->id, $id]);
            if ($decision === 'completed' && $request['type'] === 'deletion') {
                $this->pdo->prepare("UPDATE users SET status = 'deactivated', updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$request['user_id']]);
            }
            (new AuditLog($this->pdo))->record($actor->id, 'data_request.' . $decision, 'data_requests', (string) $id, ['reason_code' => $request['type']]);
        });
    }
}

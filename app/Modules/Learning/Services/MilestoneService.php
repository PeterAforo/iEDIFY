<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class MilestoneService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Staff or a startup member creates a milestone for a startup or participant. */
    public function create(Actor $actor, ?int $startupId, ?int $userId, string $title, ?string $description, string $dueOn): int
    {
        if (trim($title) === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~D', $dueOn) || ($startupId === null && $userId === null)) {
            throw new \InvalidArgumentException('A milestone needs a title, due date and owner.');
        }
        $this->authorizeOwnerOrStaff($actor, $startupId, $userId);
        return (new Transaction($this->pdo))->run(function () use ($actor, $startupId, $userId, $title, $description, $dueOn): int {
            $this->execute('INSERT INTO milestones (startup_id, user_id, title, description, due_on, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$startupId, $userId, mb_substr($title, 0, 255), $description !== null ? mb_substr($description, 0, 4000) : null, $dueOn]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'milestone.created', $id);
            return $id;
        });
    }

    /** Owner submits evidence; moves to submitted for staff review. */
    public function submitEvidence(Actor $actor, int $milestoneId, int $expectedVersion, string $evidence, ?int $mediaId = null): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $milestoneId, $expectedVersion, $evidence, $mediaId): void {
            $milestone = $this->locked($milestoneId, $expectedVersion);
            $this->authorizeOwner($actor, $milestone);
            if (!in_array($milestone['status'], ['pending', 'in_progress', 'changes_requested'], true)) {
                throw new HttpError(409, 'This milestone is not open for evidence.');
            }
            if (trim($evidence) === '' && $mediaId === null) {
                throw new HttpError(422, 'Provide evidence text or a document.');
            }
            if ($mediaId !== null) {
                $media = $this->pdo->prepare("SELECT id FROM media_assets WHERE id = ? AND classification = 'private'");
                $media->execute([$mediaId]);
                if ($media->fetchColumn() === false) {
                    throw new HttpError(422, 'Evidence documents must be uploaded through the application document flow.');
                }
            }
            $this->execute("UPDATE milestones SET status = 'submitted', evidence = ?, evidence_media_id = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [mb_substr($evidence, 0, 4000), $mediaId, $milestoneId]);
            $this->audit($actor, 'milestone.evidence_submitted', $milestoneId, ['version' => $expectedVersion + 1]);
        });
    }

    /** Staff review approves or requests changes. */
    public function review(Actor $actor, int $milestoneId, int $expectedVersion, string $decision): void
    {
        $this->authorize($actor, 'program.manage');
        if (!in_array($decision, ['approved', 'changes_requested'], true)) {
            throw new \InvalidArgumentException('Review decisions are approved or changes_requested.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $milestoneId, $expectedVersion, $decision): void {
            $milestone = $this->locked($milestoneId, $expectedVersion);
            if ($milestone['status'] !== 'submitted') {
                throw new HttpError(409, 'Only submitted milestones can be reviewed.');
            }
            $this->execute('UPDATE milestones SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6), version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$decision, $actor->id, $milestoneId]);
            if ($milestone['user_id'] !== null) {
                $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title)', [(int) $milestone['user_id'], 'milestone.reviewed.' . $milestoneId . '.' . $expectedVersion, 'milestone.reviewed', 'Milestone "' . $milestone['title'] . '" was ' . str_replace('_', ' ', $decision) . '.', '/account/milestones']);
            }
            $this->audit($actor, 'milestone.' . $decision, $milestoneId, ['version' => $expectedVersion + 1]);
        });
    }

    /** Milestones the actor can see: own/startup memberships, or staff. */
    public function forActor(Actor $actor): array
    {
        if ((new Policy())->allows($actor, 'program.manage')) {
            return $this->pdo->query('SELECT m.*, s.name AS startup_name FROM milestones m LEFT JOIN startups s ON s.id = m.startup_id ORDER BY m.due_on LIMIT 300')->fetchAll();
        }
        $statement = $this->pdo->prepare('SELECT m.*, s.name AS startup_name FROM milestones m LEFT JOIN startups s ON s.id = m.startup_id WHERE m.user_id = ? OR m.startup_id IN (SELECT startup_id FROM startup_members WHERE user_id = ?) ORDER BY m.due_on');
        $statement->execute([$actor->id, $actor->id]);
        return $statement->fetchAll();
    }

    /** Staff view: overdue open milestones and participants needing support. */
    public function overdue(): array
    {
        return $this->pdo->query("SELECT m.*, s.name AS startup_name, u.name AS owner_name FROM milestones m LEFT JOIN startups s ON s.id = m.startup_id LEFT JOIN users u ON u.id = m.user_id WHERE m.status IN ('pending','in_progress','changes_requested') AND m.due_on < UTC_DATE() ORDER BY m.due_on LIMIT 300")->fetchAll();
    }

    private function authorizeOwnerOrStaff(Actor $actor, ?int $startupId, ?int $userId): void
    {
        if ((new Policy())->allows($actor, 'program.manage')) {
            return;
        }
        if ($userId !== null && $userId === $actor->id) {
            return;
        }
        if ($startupId !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM startup_members WHERE startup_id = ? AND user_id = ?');
            $statement->execute([$startupId, $actor->id]);
            if ($statement->fetchColumn() !== false) {
                return;
            }
        }
        throw new HttpError(403, 'You do not own this milestone target.');
    }

    private function authorizeOwner(Actor $actor, array $milestone): void
    {
        if ($milestone['user_id'] !== null && (int) $milestone['user_id'] === $actor->id) {
            return;
        }
        if ($milestone['startup_id'] !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM startup_members WHERE startup_id = ? AND user_id = ?');
            $statement->execute([$milestone['startup_id'], $actor->id]);
            if ($statement->fetchColumn() !== false) {
                return;
            }
        }
        throw new HttpError(403, 'You do not own this milestone.');
    }

    private function locked(int $id, int $version): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM milestones WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Milestone was not found.');
        }
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This milestone changed. Reload it before continuing.');
        }
        return $record;
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id, array $metadata = []): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'milestone', (string) $id, $metadata);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

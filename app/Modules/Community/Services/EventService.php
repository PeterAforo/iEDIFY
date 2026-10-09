<?php

declare(strict_types=1);

namespace IEdify\Modules\Community\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class EventService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(Actor $actor, string $slug, string $title, string $description, string $location, string $startsAt, ?string $endsAt, ?int $capacity): int
    {
        $this->authorize($actor, 'program.manage');
        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || trim($title) === '' || strtotime($startsAt) === false) {
            throw new \InvalidArgumentException('A valid slug, title and start date are required.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $slug, $title, $description, $location, $startsAt, $endsAt, $capacity): int {
            $this->execute('INSERT INTO events (slug, title, description, location, starts_at, ends_at, capacity, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$slug, $title, $description, $location, gmdate('Y-m-d H:i:s', strtotime($startsAt)), $endsAt !== null ? gmdate('Y-m-d H:i:s', strtotime($endsAt)) : null, $capacity, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'event.created', $id);
            return $id;
        });
    }

    public function setStatus(Actor $actor, int $eventId, string $status, int $expectedVersion): void
    {
        $this->authorize($actor, 'program.manage');
        if (!in_array($status, ['draft', 'published', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unknown event status.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $eventId, $status, $expectedVersion): void {
            $this->locked($eventId, $expectedVersion);
            $this->execute('UPDATE events SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $eventId]);
            $this->audit($actor, 'event.status.' . $status, $eventId, ['to_status' => $status]);
        });
    }

    /** Published events for the public listing. */
    public function upcoming(): array
    {
        return $this->pdo->query("SELECT * FROM events WHERE status = 'published' AND starts_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY ORDER BY starts_at LIMIT 100")->fetchAll();
    }

    /** Published past events for the public listing, most recent first. */
    public function past(): array
    {
        return $this->pdo->query("SELECT * FROM events WHERE status = 'published' AND starts_at <= UTC_TIMESTAMP(6) - INTERVAL 1 DAY ORDER BY starts_at DESC LIMIT 50")->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM events WHERE slug = ? AND status = 'published'");
        $statement->execute([$slug]);
        $event = $statement->fetch();
        return $event === false ? null : $event;
    }

    /** Capacity-safe registration; re-registering a cancelled row revives it. */
    public function register(Actor $actor, int $eventId): void
    {
        if (!$actor->verified) {
            throw new HttpError(403, 'Sign in and verify your email to register.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $eventId): void {
            $event = $this->locked($eventId, null);
            if ($event['status'] !== 'published' || $event['starts_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new HttpError(409, 'Registration is closed for this event.');
            }
            $existing = $this->pdo->prepare('SELECT status FROM event_registrations WHERE event_id = ? AND user_id = ? FOR UPDATE');
            $existing->execute([$eventId, $actor->id]);
            $current = $existing->fetchColumn();
            if ($current === 'registered') {
                return;
            }
            if ($event['capacity'] !== null) {
                $count = $this->pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'registered' FOR UPDATE");
                $count->execute([$eventId]);
                if ((int) $count->fetchColumn() >= (int) $event['capacity']) {
                    throw new HttpError(409, 'This event is fully booked.');
                }
            }
            $this->execute("INSERT INTO event_registrations (event_id, user_id, status, created_at) VALUES (?, ?, 'registered', UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = 'registered', created_at = UTC_TIMESTAMP(6)", [$eventId, $actor->id]);
            $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$actor->id, 'event.registered.' . $eventId . '.' . $actor->id, 'event.registered', 'You are registered for ' . $event['title'] . '.', '/events/' . $event['slug']]);
            $this->audit($actor, 'event.registered', $eventId);
        });
    }

    public function cancel(Actor $actor, int $eventId): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $eventId): void {
            $updated = $this->pdo->prepare("UPDATE event_registrations SET status = 'cancelled' WHERE event_id = ? AND user_id = ? AND status = 'registered'");
            $updated->execute([$eventId, $actor->id]);
            if ($updated->rowCount() === 0) {
                throw new HttpError(409, 'You are not registered for this event.');
            }
            $this->audit($actor, 'event.registration_cancelled', $eventId);
        });
    }

    private function locked(int $id, ?int $version): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM events WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Event was not found.');
        }
        if ($version !== null && (int) $record['version'] !== $version) {
            throw new HttpError(409, 'This event changed. Reload it before saving.');
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
        (new AuditLog($this->pdo))->record($actor->id, $action, 'event', (string) $id, $metadata);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

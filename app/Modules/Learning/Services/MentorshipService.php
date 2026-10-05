<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class MentorshipService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function upsertProfile(Actor $actor, string $expertise, string $sectors, string $availability, int $capacity, bool $discoverable): void
    {
        $this->authorize($actor, 'mentoring.assigned');
        if (trim($expertise) === '' || $capacity < 0 || $capacity > 50) {
            throw new \InvalidArgumentException('Expertise and a capacity of 0-50 are required.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $expertise, $sectors, $availability, $capacity, $discoverable): void {
            $this->execute('INSERT INTO mentor_profiles (user_id, expertise, sectors, availability_notes, capacity, discoverable, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE expertise = VALUES(expertise), sectors = VALUES(sectors), availability_notes = VALUES(availability_notes), capacity = VALUES(capacity), discoverable = VALUES(discoverable), updated_at = UTC_TIMESTAMP(6)', [$actor->id, mb_substr($expertise, 0, 4000), mb_substr($sectors, 0, 500), mb_substr($availability, 0, 1000), $capacity, $discoverable ? 1 : 0]);
            $this->audit($actor, 'mentor.profile_saved', $actor->id);
        });
    }

    /** Coordinator proposes a match; both sides can respond. */
    public function proposeMatch(Actor $actor, int $mentorId, int $participantId): int
    {
        $this->authorize($actor, 'mentoring.coordinate');
        return (new Transaction($this->pdo))->run(function () use ($actor, $mentorId, $participantId): int {
            $capacity = $this->pdo->prepare("SELECT capacity FROM mentor_profiles WHERE user_id = ?");
            $capacity->execute([$mentorId]);
            $limit = $capacity->fetchColumn();
            if ($limit === false) {
                throw new HttpError(422, 'That user has no mentor profile.');
            }
            $active = $this->pdo->prepare("SELECT COUNT(*) FROM mentor_matches WHERE mentor_id = ? AND status IN ('proposed','accepted')");
            $active->execute([$mentorId]);
            if ((int) $active->fetchColumn() >= (int) $limit) {
                throw new HttpError(409, 'This mentor is at capacity.');
            }
            $open = $this->pdo->prepare("SELECT COUNT(*) FROM mentor_matches WHERE mentor_id = ? AND participant_id = ? AND status IN ('proposed','accepted')");
            $open->execute([$mentorId, $participantId]);
            if ((int) $open->fetchColumn() > 0) {
                throw new HttpError(409, 'This pair already has an open match.');
            }
            $this->execute("INSERT INTO mentor_matches (mentor_id, participant_id, proposed_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))", [$mentorId, $participantId, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->notify($mentorId, 'mentoring.match_proposed', 'A mentorship match was proposed for you.', '/account/mentoring');
            $this->notify($participantId, 'mentoring.match_proposed', 'A mentor match was proposed for you.', '/account/mentoring');
            $this->audit($actor, 'mentoring.match_proposed', $id);
            return $id;
        });
    }

    /** Mentor or participant accepts/declines the proposal. */
    public function respondToMatch(Actor $actor, int $matchId, string $decision): void
    {
        if (!in_array($decision, ['accepted', 'declined'], true)) {
            throw new \InvalidArgumentException('Decisions are accepted or declined.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $matchId, $decision): void {
            $match = $this->match($matchId, true);
            if ($match['status'] !== 'proposed') {
                throw new HttpError(409, 'This match was already decided.');
            }
            if ($actor->id !== (int) $match['mentor_id'] && $actor->id !== (int) $match['participant_id']) {
                throw new HttpError(403, 'Only the matched mentor or participant can respond.');
            }
            $this->execute('UPDATE mentor_matches SET status = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ?', [$decision, $matchId]);
            $other = $actor->id === (int) $match['mentor_id'] ? (int) $match['participant_id'] : (int) $match['mentor_id'];
            $this->notify($other, 'mentoring.match_' . $decision, 'A mentorship match was ' . $decision . '.', '/account/mentoring');
            $this->audit($actor, 'mentoring.match_' . $decision, $matchId);
        });
    }

    /** Schedule a session on an accepted match. */
    public function scheduleSession(Actor $actor, int $matchId, string $scheduledAt, int $minutes, ?string $agenda, ?string $meetingLink): int
    {
        return (new Transaction($this->pdo))->run(function () use ($actor, $matchId, $scheduledAt, $minutes, $agenda, $meetingLink): int {
            $match = $this->match($matchId);
            $this->authorizeParty($actor, $match, 'mentoring.schedule');
            if ($match['status'] !== 'accepted') {
                throw new HttpError(409, 'Sessions need an accepted match.');
            }
            $when = strtotime($scheduledAt);
            if ($when === false || $when <= time()) {
                throw new \InvalidArgumentException('Sessions must be scheduled in the future.');
            }
            if ($minutes < 15 || $minutes > 480 || ($meetingLink !== null && $meetingLink !== '' && !preg_match('~^https://~iD', $meetingLink))) {
                throw new \InvalidArgumentException('Sessions are 15-480 minutes; links must be https.');
            }
            $this->execute('INSERT INTO mentor_sessions (match_id, scheduled_at, duration_minutes, agenda, meeting_link, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$matchId, gmdate('Y-m-d H:i:s', $when), $minutes, $agenda, $meetingLink, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $other = $actor->id === (int) $match['mentor_id'] ? (int) $match['participant_id'] : (int) $match['mentor_id'];
            $this->notify($other, 'mentoring.session_scheduled', 'A mentoring session was scheduled for ' . gmdate('j M Y H:i', $when) . ' UTC.', '/account/mentoring');
            $this->audit($actor, 'mentoring.session_scheduled', $id);
            return $id;
        });
    }

    public function rescheduleSession(Actor $actor, int $sessionId, string $scheduledAt): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $sessionId, $scheduledAt): void {
            $session = $this->session($sessionId, true);
            $this->authorizeParty($actor, $this->match((int) $session['match_id']), 'mentoring.schedule');
            if ($session['status'] !== 'scheduled') {
                throw new HttpError(409, 'Only scheduled sessions can be moved.');
            }
            $when = strtotime($scheduledAt);
            if ($when === false || $when <= time()) {
                throw new \InvalidArgumentException('Sessions must be rescheduled to a future time.');
            }
            $this->execute('UPDATE mentor_sessions SET scheduled_at = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [gmdate('Y-m-d H:i:s', $when), $sessionId]);
            $this->audit($actor, 'mentoring.session_rescheduled', $sessionId);
        });
    }

    public function cancelSession(Actor $actor, int $sessionId): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $sessionId): void {
            $session = $this->session($sessionId, true);
            $this->authorizeParty($actor, $this->match((int) $session['match_id']), 'mentoring.schedule');
            if ($session['status'] !== 'scheduled') {
                throw new HttpError(409, 'Only scheduled sessions can be cancelled.');
            }
            $this->execute("UPDATE mentor_sessions SET status = 'cancelled', updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$sessionId]);
            $this->audit($actor, 'mentoring.session_cancelled', $sessionId);
        });
    }

    /** Record outcome and progress notes; 'private' notes stay staff-only. */
    public function recordOutcome(Actor $actor, int $sessionId, string $outcome, ?string $notes, string $visibility): void
    {
        if (!in_array($visibility, ['private', 'shared'], true)) {
            throw new \InvalidArgumentException('Note visibility is private or shared.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $sessionId, $outcome, $notes, $visibility): void {
            $session = $this->session($sessionId, true);
            $match = $this->match((int) $session['match_id']);
            $this->authorizeParty($actor, $match, 'mentoring.assigned');
            if ($session['status'] !== 'scheduled') {
                throw new HttpError(409, 'Only scheduled sessions can be completed.');
            }
            $this->execute("UPDATE mentor_sessions SET status = 'completed', outcome = ?, notes = ?, notes_visibility = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [mb_substr($outcome, 0, 4000), $notes !== null ? mb_substr($notes, 0, 4000) : null, $visibility, $sessionId]);
            $this->audit($actor, 'mentoring.session_completed', $sessionId);
        });
    }

    /** Sessions visible to the actor: coordinators see all, parties see theirs (private notes hidden from participants). */
    public function sessionsFor(Actor $actor): array
    {
        if ((new Policy())->allows($actor, 'mentoring.coordinate')) {
            return $this->pdo->query('SELECT s.*, m.mentor_id, m.participant_id FROM mentor_sessions s JOIN mentor_matches m ON m.id = s.match_id ORDER BY s.scheduled_at DESC LIMIT 200')->fetchAll();
        }
        $statement = $this->pdo->prepare('SELECT s.*, m.mentor_id, m.participant_id FROM mentor_sessions s JOIN mentor_matches m ON m.id = s.match_id WHERE m.mentor_id = ? OR m.participant_id = ? ORDER BY s.scheduled_at DESC LIMIT 200');
        $statement->execute([$actor->id, $actor->id]);
        $sessions = $statement->fetchAll();
        $isMentor = (new Policy())->allows($actor, 'mentoring.assigned');
        foreach ($sessions as &$session) {
            if (!$isMentor && $session['notes_visibility'] === 'private') {
                $session['notes'] = null;
            }
        }
        return $sessions;
    }

    /** Mentors see only their assigned participants; coordinators see all matches. */
    public function matchesFor(Actor $actor): array
    {
        if ((new Policy())->allows($actor, 'mentoring.coordinate')) {
            return $this->pdo->query('SELECT m.*, mu.name AS mentor_name, pu.name AS participant_name FROM mentor_matches m JOIN users mu ON mu.id = m.mentor_id JOIN users pu ON pu.id = m.participant_id ORDER BY m.id DESC LIMIT 200')->fetchAll();
        }
        $statement = $this->pdo->prepare('SELECT m.*, mu.name AS mentor_name, pu.name AS participant_name FROM mentor_matches m JOIN users mu ON mu.id = m.mentor_id JOIN users pu ON pu.id = m.participant_id WHERE m.mentor_id = ? OR m.participant_id = ? ORDER BY m.id DESC');
        $statement->execute([$actor->id, $actor->id]);
        return $statement->fetchAll();
    }

    private function match(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mentor_matches WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $match = $statement->fetch();
        if ($match === false) {
            throw new HttpError(404, 'Match was not found.');
        }
        return $match;
    }

    private function session(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mentor_sessions WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $session = $statement->fetch();
        if ($session === false) {
            throw new HttpError(404, 'Session was not found.');
        }
        return $session;
    }

    private function authorizeParty(Actor $actor, array $match, string $permission): void
    {
        $policy = new Policy();
        if ($policy->allows($actor, 'mentoring.coordinate')) {
            return;
        }
        $isParty = $actor->id === (int) $match['mentor_id'] || $actor->id === (int) $match['participant_id'];
        if (!$isParty || !$policy->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission for this match.');
        }
    }

    private function notify(int $userId, string $type, string $title, string $link): void
    {
        $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$userId, $type . '.' . bin2hex(random_bytes(8)), $type, $title, $link]);
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'mentoring', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Modules\Funding\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Funding rounds, requests, conflict-of-interest declarations, weighted
 * scorecards and approvals. Rule versions are frozen at submission so a
 * request is always scored against the rubric in force when it arrived.
 * Evaluation (funding.review), approval (funding.approve) and award
 * (award.authorize) are separate permissions; approvers may not be the
 * requester or one of the reviewers.
 */
final readonly class FundingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createRound(Actor $actor, string $slug, string $title, string $description, string $currency, ?string $maxAward, string $opensAt, string $closesAt): int
    {
        $this->authorize($actor, 'funding.manage');
        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || trim($title) === '' || !preg_match('~^[A-Z]{3}$~D', $currency) || strtotime($opensAt) === false || strtotime($closesAt) === false || strtotime($closesAt) <= strtotime($opensAt)) {
            throw new \InvalidArgumentException('A round needs a slug, title, ISO currency and a valid window.');
        }
        if ($maxAward !== null && (!is_numeric($maxAward) || (float) $maxAward <= 0)) {
            throw new \InvalidArgumentException('Maximum award must be a positive amount.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $slug, $title, $description, $currency, $maxAward, $opensAt, $closesAt): int {
            $this->execute('INSERT INTO funding_rounds (slug, title, description, currency, max_award, opens_at, closes_at, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$slug, $title, $description, $currency, $maxAward, gmdate('Y-m-d H:i:s', strtotime($opensAt)), gmdate('Y-m-d H:i:s', strtotime($closesAt)), $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'funding.round_created', $id);
            return $id;
        });
    }

    public function setRoundStatus(Actor $actor, int $roundId, string $status, int $expectedVersion): void
    {
        $this->authorize($actor, 'funding.manage');
        if (!in_array($status, ['draft', 'open', 'closed', 'archived'], true)) {
            throw new \InvalidArgumentException('Unknown round status.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $roundId, $status, $expectedVersion): void {
            $this->locked('funding_rounds', $roundId, $expectedVersion);
            $this->execute('UPDATE funding_rounds SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $roundId]);
            $this->audit($actor, 'funding.round_' . $status, $roundId);
        });
    }

    /** Constrained custom request form for a round (same field types as application forms). */
    public function setRoundForm(Actor $actor, int $roundId, array $fields): void
    {
        $this->authorize($actor, 'funding.manage');
        $clean = (new \IEdify\Modules\Programs\Services\FormDefinition())->validate($fields);
        (new Transaction($this->pdo))->run(function () use ($actor, $roundId, $clean): void {
            $this->record('funding_rounds', $roundId, true);
            $this->execute('UPDATE funding_rounds SET form_schema = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [json_encode($clean, JSON_THROW_ON_ERROR), $roundId]);
            $this->audit($actor, 'funding.form_versioned', $roundId);
        });
    }

    /** New immutable scorecard version; weights are criterion => weight (0-100). */
    public function createRuleVersion(Actor $actor, int $roundId, array $weights, int $maxScore = 5): int
    {
        $this->authorize($actor, 'funding.manage');
        $clean = [];
        foreach ($weights as $criterion => $weight) {
            $criterion = trim((string) $criterion);
            if ($criterion === '' || mb_strlen($criterion) > 120 || !is_numeric($weight) || (float) $weight <= 0 || (float) $weight > 100) {
                throw new \InvalidArgumentException('Weights need named criteria between 0 and 100.');
            }
            $clean[$criterion] = (float) $weight;
        }
        if ($clean === [] || count($clean) > 20 || $maxScore < 1 || $maxScore > 10) {
            throw new \InvalidArgumentException('A scorecard needs 1-20 criteria and a max score of 1-10.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $roundId, $clean, $maxScore): int {
            $this->record('funding_rounds', $roundId, true);
            $next = (int) $this->pdo->query("SELECT COALESCE(MAX(version), 0) + 1 FROM funding_rule_versions WHERE round_id = {$roundId}")->fetchColumn();
            $this->execute('INSERT INTO funding_rule_versions (round_id, version, weights_json, max_score, created_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$roundId, $next, json_encode($clean, JSON_THROW_ON_ERROR), $maxScore, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'funding.rules_versioned', $id);
            return $id;
        });
    }

    /** Startup/participant submits a request; the current rule version freezes onto it. */
    public function submitRequest(Actor $actor, int $roundId, string $title, string $amount, ?string $budgetSummary, ?int $startupId = null, ?array $answers = null, ?int $budgetMediaId = null): int
    {
        if (!(new Policy())->allows($actor, 'startup.team') && !(new Policy())->allows($actor, 'funding.manage')) {
            throw new HttpError(403, 'You do not have permission to request funding.');
        }
        if (trim($title) === '' || !is_numeric($amount) || (float) $amount <= 0) {
            throw new \InvalidArgumentException('A request needs a title and a positive amount.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $roundId, $title, $amount, $budgetSummary, $startupId, $answers, $budgetMediaId): int {
            $round = $this->record('funding_rounds', $roundId, true);
            $now = gmdate('Y-m-d H:i:s');
            if ($round['status'] !== 'open' || $round['opens_at'] > $now || $round['closes_at'] < $now) {
                throw new HttpError(409, 'This funding round is not accepting requests.');
            }
            if ($round['max_award'] !== null && bccomp($amount, (string) $round['max_award'], 2) === 1) {
                throw new HttpError(422, 'The requested amount exceeds the round maximum.');
            }
            if ($startupId !== null) {
                $member = $this->pdo->prepare('SELECT id FROM startup_members WHERE startup_id = ? AND user_id = ?');
                $member->execute([$startupId, $actor->id]);
                if ($member->fetchColumn() === false && !(new Policy())->allows($actor, 'funding.manage')) {
                    throw new HttpError(403, 'Only startup members can request funding for it.');
                }
            }
            $rules = $this->pdo->prepare('SELECT id FROM funding_rule_versions WHERE round_id = ? ORDER BY version DESC LIMIT 1');
            $rules->execute([$roundId]);
            $ruleVersion = $rules->fetchColumn();
            if ($ruleVersion === false) {
                throw new HttpError(409, 'No scoring rules are configured for this round.');
            }
            $validatedAnswers = null;
            if ($round['form_schema'] !== null) {
                $fields = json_decode((string) $round['form_schema'], true, 512, JSON_THROW_ON_ERROR);
                $validatedAnswers = json_encode((new \IEdify\Modules\Programs\Services\FormDefinition())->validateAnswers($fields, $answers ?? [], true), JSON_THROW_ON_ERROR);
            }
            if ($budgetMediaId !== null) {
                $media = $this->pdo->prepare('SELECT id FROM media_assets WHERE id = ?');
                $media->execute([$budgetMediaId]);
                if ($media->fetchColumn() === false) {
                    throw new \InvalidArgumentException('The budget document is not a valid upload.');
                }
            }
            $this->execute("INSERT INTO funding_requests (public_id, round_id, startup_id, user_id, title, requested_amount, currency, budget_summary, status, rule_version_id, answers, budget_media_id, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [bin2hex(random_bytes(16)), $roundId, $startupId, $actor->id, mb_substr($title, 0, 255), $amount, $round['currency'], $budgetSummary !== null ? mb_substr($budgetSummary, 0, 8000) : null, (int) $ruleVersion, $validatedAnswers, $budgetMediaId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$actor->id, 'funding.submitted.' . $id, 'funding.submitted', 'Your funding request was received.', '/account/funding']);
            $this->audit($actor, 'funding.request_submitted', $id);
            return $id;
        });
    }

    /** Reviewer declares a conflict of interest; blocks their scoring until staff clear it. */
    public function declareConflict(Actor $actor, int $requestId, string $declaration): void
    {
        $this->authorize($actor, 'funding.review');
        if (trim($declaration) === '') {
            throw new \InvalidArgumentException('Describe the conflict.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $requestId, $declaration): void {
            $this->record('funding_requests', $requestId);
            $this->execute("INSERT INTO funding_conflicts (request_id, reviewer_id, declaration, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))", [$requestId, $actor->id, mb_substr($declaration, 0, 2000)]);
            $this->audit($actor, 'funding.conflict_declared', $requestId);
        });
    }

    public function clearConflict(Actor $actor, int $conflictId): void
    {
        $this->authorize($actor, 'funding.manage');
        (new Transaction($this->pdo))->run(function () use ($actor, $conflictId): void {
            $updated = $this->pdo->prepare("UPDATE funding_conflicts SET status = 'cleared', cleared_by = ?, cleared_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'declared'");
            $updated->execute([$actor->id, $conflictId]);
            if ($updated->rowCount() === 0) {
                throw new HttpError(409, 'There is no open conflict to clear.');
            }
            $this->audit($actor, 'funding.conflict_cleared', $conflictId);
        });
    }

    /**
     * Reviewer scores against the frozen rule version. Scores outside the
     * card, missing criteria, extra criteria, self-review and open conflicts
     * all fail safely.
     */
    public function recordReview(Actor $actor, int $requestId, array $scores, string $recommendation, ?string $notes): void
    {
        $this->authorize($actor, 'funding.review');
        if (!in_array($recommendation, ['award', 'decline', 'hold'], true)) {
            throw new \InvalidArgumentException('Recommendations are award, decline or hold.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $requestId, $scores, $recommendation, $notes): void {
            $request = $this->record('funding_requests', $requestId, true);
            if ($actor->id === (int) $request['user_id']) {
                throw new HttpError(403, 'You cannot review your own request.');
            }
            if (!in_array($request['status'], ['submitted', 'under_review'], true)) {
                throw new HttpError(409, 'This request is no longer open for review.');
            }
            $conflict = $this->pdo->prepare("SELECT id FROM funding_conflicts WHERE request_id = ? AND reviewer_id = ? AND status = 'declared'");
            $conflict->execute([$requestId, $actor->id]);
            if ($conflict->fetchColumn() !== false) {
                throw new HttpError(403, 'A declared conflict must be cleared before reviewing.');
            }
            $rules = $this->record('funding_rule_versions', (int) $request['rule_version_id']);
            $weights = json_decode($rules['weights_json'], true, 512, JSON_THROW_ON_ERROR);
            if (array_diff_key($scores, $weights) !== [] || array_diff_key($weights, $scores) !== []) {
                throw new \InvalidArgumentException('Scores must cover exactly the frozen criteria.');
            }
            $total = '0';
            $clean = [];
            foreach ($weights as $criterion => $weight) {
                $score = $scores[$criterion];
                if (!is_numeric($score) || (float) $score < 0 || (float) $score > (int) $rules['max_score']) {
                    throw new \InvalidArgumentException("Score for {$criterion} must be 0-{$rules['max_score']}.");
                }
                $clean[$criterion] = (float) $score;
                $total = bcadd($total, bcmul((string) $score, (string) $weight, 4), 4);
            }
            $this->execute('INSERT INTO funding_reviews (request_id, reviewer_id, rule_version_id, scores_json, weighted_total, recommendation, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE scores_json = VALUES(scores_json), weighted_total = VALUES(weighted_total), recommendation = VALUES(recommendation), notes = VALUES(notes)', [$requestId, $actor->id, (int) $request['rule_version_id'], json_encode($clean, JSON_THROW_ON_ERROR), $total, $recommendation, $notes !== null ? mb_substr($notes, 0, 8000) : null]);
            $this->execute("UPDATE funding_requests SET status = 'under_review', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'submitted'", [$requestId]);
            $this->audit($actor, 'funding.review_recorded', $requestId);
        });
    }

    /**
     * Approver decides. Separation of duties: the approver may not be the
     * requester and may not be a reviewer of this request.
     */
    public function approve(Actor $actor, int $requestId, string $decision, ?string $reason): void
    {
        $this->authorize($actor, 'funding.approve');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Decisions are approved or rejected.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $requestId, $decision, $reason): void {
            $request = $this->record('funding_requests', $requestId, true);
            if ($actor->id === (int) $request['user_id']) {
                throw new HttpError(403, 'You cannot approve your own request.');
            }
            $reviewed = $this->pdo->prepare('SELECT id FROM funding_reviews WHERE request_id = ? AND reviewer_id = ?');
            $reviewed->execute([$requestId, $actor->id]);
            if ($reviewed->fetchColumn() !== false) {
                throw new HttpError(403, 'A reviewer cannot approve a request they scored.');
            }
            if (!in_array($request['status'], ['submitted', 'under_review', 'recommended'], true)) {
                throw new HttpError(409, 'This request already has a final decision.');
            }
            $this->execute('INSERT INTO funding_approvals (request_id, approver_id, decision, reason, rule_version_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$requestId, $actor->id, $decision, $reason !== null ? mb_substr($reason, 0, 1000) : null, (int) $request['rule_version_id']]);
            $this->execute('UPDATE funding_requests SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$decision === 'approved' ? 'approved' : 'rejected', $requestId]);
            $this->notify((int) $request['user_id'], 'funding.' . $decision, 'Your funding request was ' . $decision . '.', '/account/funding');
            $this->audit($actor, 'funding.' . $decision, $requestId);
        });
    }

    /** Award an approved request; cannot exceed the request or round maximum. */
    public function award(Actor $actor, int $requestId, string $amount, ?string $conditions): int
    {
        $this->authorize($actor, 'award.authorize');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new \InvalidArgumentException('The award needs a positive amount.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $requestId, $amount, $conditions): int {
            $request = $this->record('funding_requests', $requestId, true);
            if ($request['status'] !== 'approved') {
                throw new HttpError(409, 'Only approved requests can be awarded.');
            }
            if ($actor->id === (int) $request['user_id']) {
                throw new HttpError(403, 'You cannot award your own request.');
            }
            if (bccomp($amount, (string) $request['requested_amount'], 2) === 1) {
                throw new HttpError(422, 'The award cannot exceed the requested amount.');
            }
            $round = $this->record('funding_rounds', (int) $request['round_id']);
            if ($round['max_award'] !== null && bccomp($amount, (string) $round['max_award'], 2) === 1) {
                throw new HttpError(422, 'The award exceeds the round maximum.');
            }
            $this->execute("INSERT INTO awards (request_id, amount, currency, conditions, awarded_by, awarded_at, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$requestId, $amount, $request['currency'], $conditions !== null ? mb_substr($conditions, 0, 8000) : null, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->execute("UPDATE funding_requests SET status = 'awarded', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$requestId]);
            $this->notify((int) $request['user_id'], 'funding.awarded', 'Your funding request was awarded.', '/account/funding');
            $this->audit($actor, 'funding.awarded', $id);
            return $id;
        });
    }

    public function rounds(): array
    {
        return $this->pdo->query('SELECT r.*, (SELECT COUNT(*) FROM funding_requests q WHERE q.round_id = r.id) AS requests FROM funding_rounds r ORDER BY r.id DESC LIMIT 200')->fetchAll();
    }

    public function requestsForStaff(?string $status = null): array
    {
        $sql = 'SELECT q.*, r.title AS round_title, u.name AS applicant_name, u.email AS applicant_email FROM funding_requests q JOIN funding_rounds r ON r.id = q.round_id JOIN users u ON u.id = q.user_id';
        if ($status !== null) {
            $sql .= " WHERE q.status = " . $this->pdo->quote($status);
        }
        return $this->pdo->query($sql . ' ORDER BY q.id DESC LIMIT 300')->fetchAll();
    }

    public function requestsForActor(Actor $actor): array
    {
        $statement = $this->pdo->prepare('SELECT q.*, r.title AS round_title, (SELECT a.amount FROM awards a WHERE a.request_id = q.id) AS awarded_amount FROM funding_requests q JOIN funding_rounds r ON r.id = q.round_id WHERE q.user_id = ? ORDER BY q.id DESC');
        $statement->execute([$actor->id]);
        return $statement->fetchAll();
    }

    public function requestDetail(int $requestId): array
    {
        $request = $this->record('funding_requests', $requestId);
        $reviews = $this->pdo->prepare('SELECT r.*, u.name AS reviewer_name FROM funding_reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.request_id = ?');
        $reviews->execute([$requestId]);
        $request['reviews'] = $reviews->fetchAll();
        $conflicts = $this->pdo->prepare('SELECT c.*, u.name AS reviewer_name FROM funding_conflicts c JOIN users u ON u.id = c.reviewer_id WHERE c.request_id = ?');
        $conflicts->execute([$requestId]);
        $request['conflicts'] = $conflicts->fetchAll();
        $award = $this->pdo->prepare('SELECT * FROM awards WHERE request_id = ?');
        $award->execute([$requestId]);
        $award = $award->fetch();
        $request['award'] = $award !== false ? $award : null;
        if ($award !== false) {
            $disbursements = $this->pdo->prepare('SELECT * FROM disbursements WHERE award_id = ? ORDER BY scheduled_on');
            $disbursements->execute([(int) $award['id']]);
            $request['disbursements'] = $disbursements->fetchAll();
        } else {
            $request['disbursements'] = [];
        }
        $approval = $this->pdo->prepare('SELECT a.*, u.name AS approver_name FROM funding_approvals a JOIN users u ON u.id = a.approver_id WHERE a.request_id = ?');
        $approval->execute([$requestId]);
        $request['approval'] = $approval->fetch() ?: null;
        $rules = $this->pdo->prepare('SELECT * FROM funding_rule_versions WHERE id = ?');
        $rules->execute([(int) $request['rule_version_id']]);
        $request['rules'] = $rules->fetch() ?: null;
        if ($request['rules'] !== null) {
            $request['rules']['weights'] = json_decode((string) $request['rules']['weights_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        $request['answers'] = $request['answers'] !== null ? json_decode((string) $request['answers'], true, 512, JSON_THROW_ON_ERROR) : null;
        return $request;
    }

    private function record(string $table, int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Record was not found.');
        }
        return $record;
    }

    private function locked(string $table, int $id, int $version): array
    {
        $record = $this->record($table, $id, true);
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This record changed. Reload it before saving.');
        }
        return $record;
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
        (new AuditLog($this->pdo))->record($actor->id, $action, 'funding', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

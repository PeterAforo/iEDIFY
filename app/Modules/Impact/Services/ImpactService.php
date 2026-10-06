<?php

declare(strict_types=1);

namespace IEdify\Modules\Impact\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Indicator dictionary, targets, measured results and published reports.
 * Targets and actuals are separate tables; results move
 * submitted -> verified -> published and the verifier/publisher can never
 * be the submitter. Each result carries a dedupe key over its dimensions
 * so the same measure cannot be counted twice.
 */
final readonly class ImpactService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createIndicator(Actor $actor, string $code, string $name, string $definition, string $unit, string $source, string $calculation, string $frequency, ?array $disaggregation, ?string $validation): int
    {
        $this->authorize($actor, 'impact.edit');
        if (!preg_match('~^[A-Z][A-Z0-9_]{1,40}$~D', $code) || trim($name) === '' || trim($definition) === '' || trim($unit) === '') {
            throw new \InvalidArgumentException('An indicator needs an UPPER_SNAKE code, name, definition and unit.');
        }
        if (!in_array($frequency, ['monthly', 'quarterly', 'annual'], true)) {
            throw new \InvalidArgumentException('Frequency must be monthly, quarterly or annual.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $code, $name, $definition, $unit, $source, $calculation, $frequency, $disaggregation, $validation): int {
            $this->execute('INSERT INTO impact_indicators (code, name, definition, unit, source, calculation, disaggregation, frequency, validation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$code, mb_substr($name, 0, 180), $definition, mb_substr($unit, 0, 60), mb_substr($source, 0, 120), mb_substr($calculation, 0, 255), $disaggregation !== null ? json_encode($disaggregation, JSON_THROW_ON_ERROR) : null, $frequency, $validation]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'impact.indicator_created', $id);
            return $id;
        });
    }

    public function setTarget(Actor $actor, int $indicatorId, string $period, string $target, ?int $programId): void
    {
        $this->authorize($actor, 'impact.edit');
        $this->assertPeriod($period);
        if (!is_numeric($target) || (float) $target < 0) {
            throw new \InvalidArgumentException('Targets need a non-negative value.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $indicatorId, $period, $target, $programId): void {
            $this->record('impact_indicators', $indicatorId);
            if ($programId !== null) {
                $this->record('programs', $programId);
            }
            $this->execute('INSERT INTO impact_targets (indicator_id, period, program_id, target, set_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE target = VALUES(target), set_by = VALUES(set_by)', [$indicatorId, $period, $programId, $target, $actor->id]);
            $this->audit($actor, 'impact.target_set', $indicatorId);
        });
    }

    /**
     * $disaggregation labels the subgroup (e.g. {"sex":"female"}); $groupSize is
     * the number of respondents in that subgroup and drives public small-group
     * suppression. Each distinct disaggregation is its own dedupe bucket.
     */
    public function submitResult(Actor $actor, int $indicatorId, string $period, string $value, ?int $programId, ?int $cohortId, ?string $geography, ?array $disaggregation, ?string $sourceNote, ?int $evidenceMediaId, ?int $groupSize = null): int
    {
        $this->authorize($actor, 'impact.submit');
        $this->assertPeriod($period);
        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('Results need a non-negative value.');
        }
        if ($disaggregation !== null && $groupSize === null) {
            throw new \InvalidArgumentException('Disaggregated results must record the group size so small groups can be suppressed publicly.');
        }
        $disagg = $disaggregation !== null ? json_encode($disaggregation, JSON_THROW_ON_ERROR) : null;
        return (new Transaction($this->pdo))->run(function () use ($actor, $indicatorId, $period, $value, $programId, $cohortId, $geography, $disagg, $sourceNote, $evidenceMediaId, $groupSize): int {
            $this->record('impact_indicators', $indicatorId);
            $dedupe = hash('sha256', implode('|', [(string) $indicatorId, $period, (string) ($programId ?? ''), (string) ($cohortId ?? ''), (string) ($geography ?? ''), (string) ($disagg ?? '')]));
            $existing = $this->pdo->prepare("SELECT id, status FROM impact_results WHERE dedupe_key = ? AND status != 'rejected'");
            $existing->execute([$dedupe]);
            if ($existing->fetch() !== false) {
                throw new HttpError(409, 'This indicator/period/segment was already reported.');
            }
            $this->execute("INSERT INTO impact_results (dedupe_key, indicator_id, period, program_id, cohort_id, geography, value, disaggregation, group_size, source_note, evidence_media_id, submitted_by, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$dedupe, $indicatorId, $period, $programId, $cohortId, $geography !== null ? mb_substr($geography, 0, 120) : null, $value, $disagg, $groupSize, $sourceNote, $evidenceMediaId, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'impact.result_submitted', $id);
            return $id;
        });
    }

    public function verify(Actor $actor, int $resultId, int $expectedVersion): void
    {
        $this->authorize($actor, 'impact.verify');
        $this->transition($actor, $resultId, $expectedVersion, 'submitted', 'verified', 'impact.result_verified', 'verified_by', 'verified_at');
    }

    public function publish(Actor $actor, int $resultId, int $expectedVersion): void
    {
        $this->authorize($actor, 'impact.publish');
        $this->transition($actor, $resultId, $expectedVersion, 'verified', 'published', 'impact.result_published', 'published_by', 'published_at');
    }

    public function reject(Actor $actor, int $resultId, int $expectedVersion): void
    {
        $this->authorize($actor, 'impact.verify');
        (new Transaction($this->pdo))->run(function () use ($actor, $resultId, $expectedVersion): void {
            $record = $this->locked($resultId, $expectedVersion);
            if ($actor->id === (int) $record['submitted_by']) {
                throw new HttpError(403, 'You cannot review your own result.');
            }
            if (!in_array($record['status'], ['submitted', 'verified'], true)) {
                throw new HttpError(409, 'Only unpublished results can be rejected.');
            }
            $this->execute("UPDATE impact_results SET status = 'rejected', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$resultId]);
            $this->audit($actor, 'impact.result_rejected', $resultId);
        });
    }

    public function createReport(Actor $actor, string $title, string $period, string $summary, string $body): int
    {
        $this->authorize($actor, 'impact.edit');
        if (trim($title) === '' || trim($summary) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('A report needs a title, summary and body.');
        }
        $this->assertPeriod($period);
        return (new Transaction($this->pdo))->run(function () use ($actor, $title, $period, $summary, $body): int {
            $this->execute("INSERT INTO impact_reports (title, period, summary, body, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [mb_substr($title, 0, 255), $period, $summary, $body, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'impact.report_created', $id);
            return $id;
        });
    }

    /** Approver must differ from the author. */
    public function approveReport(Actor $actor, int $reportId, int $expectedVersion): void
    {
        $this->authorize($actor, 'impact.publish');
        (new Transaction($this->pdo))->run(function () use ($actor, $reportId, $expectedVersion): void {
            $report = $this->lockedReport($reportId, $expectedVersion);
            if ($actor->id === (int) $report['created_by']) {
                throw new HttpError(403, 'You cannot approve your own report.');
            }
            if (!in_array($report['status'], ['draft', 'approved'], true)) {
                throw new HttpError(409, 'This report cannot be approved.');
            }
            $this->execute("UPDATE impact_reports SET status = 'approved', approved_by = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$actor->id, $reportId]);
            $this->audit($actor, 'impact.report_approved', $reportId);
        });
    }

    public function publishReport(Actor $actor, int $reportId, int $expectedVersion): void
    {
        $this->authorize($actor, 'impact.publish');
        (new Transaction($this->pdo))->run(function () use ($actor, $reportId, $expectedVersion): void {
            $report = $this->lockedReport($reportId, $expectedVersion);
            if ($report['status'] !== 'approved') {
                throw new HttpError(409, 'Only approved reports can be published.');
            }
            $this->execute("UPDATE impact_reports SET status = 'published', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$reportId]);
            $this->audit($actor, 'impact.report_published', $reportId);
        });
    }

    /** Public-facing: published actuals with targets alongside; never sums across currencies. */
    public function publicSummary(?string $period = null): array
    {
        $sql = "SELECT i.code, i.name, i.unit, r.period, r.geography, SUM(r.value) AS actual,
                (SELECT SUM(t.target) FROM impact_targets t WHERE t.indicator_id = i.id AND t.period = r.period) AS target
                FROM impact_results r JOIN impact_indicators i ON i.id = r.indicator_id
                WHERE r.status = 'published' AND i.status = 'active'";
        if ($period !== null) {
            $sql .= ' AND r.period = ' . $this->pdo->quote($period);
        }
        return $this->pdo->query($sql . " GROUP BY i.code, i.name, i.unit, r.period, r.geography ORDER BY r.period DESC, i.code, r.geography")->fetchAll();
    }

    /**
     * Public disaggregated breakdowns. Groups with a recorded respondent count
     * below $threshold are withheld entirely (returned only as a count so the
     * page can explain the suppression) — their values are never exposed.
     *
     * @return array{rows: array<int,array<string,mixed>>, suppressed: int}
     */
    public function publicBreakdown(?string $period, int $threshold): array
    {
        $sql = "SELECT i.code, i.name, i.unit, r.period, r.geography, r.value, r.disaggregation, r.group_size
                FROM impact_results r JOIN impact_indicators i ON i.id = r.indicator_id
                WHERE r.status = 'published' AND i.status = 'active' AND r.disaggregation IS NOT NULL";
        $params = [];
        if ($period !== null) {
            $sql .= ' AND r.period = ?';
            $params[] = $period;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY i.code, r.period DESC, r.geography');
        $statement->execute($params);
        $rows = [];
        $suppressed = 0;
        foreach ($statement->fetchAll() as $row) {
            if ($row['group_size'] !== null && (int) $row['group_size'] < $threshold) {
                $suppressed++;
                continue;
            }
            $row['disaggregation'] = json_decode((string) $row['disaggregation'], true);
            $rows[] = $row;
        }
        return ['rows' => $rows, 'suppressed' => $suppressed];
    }

    /**
     * Chart.js configs from publicSummary rows, one chart per unit so mixed
     * units never share an axis. Every chart renders next to its HTML table.
     */
    public function chartConfig(array $rows): array
    {
        $byUnit = [];
        foreach ($rows as $row) {
            $unit = (string) $row['unit'];
            $byUnit[$unit]['labels'][] = $row['code'] . ' ' . $row['period'] . ($row['geography'] !== null ? ' · ' . $row['geography'] : '');
            $byUnit[$unit]['actual'][] = (float) $row['actual'];
            $byUnit[$unit]['target'][] = $row['target'] !== null ? (float) $row['target'] : null;
        }
        $charts = [];
        foreach ($byUnit as $unit => $data) {
            $charts[] = [
                'unit' => $unit,
                'config' => [
                    'type' => 'bar',
                    'data' => [
                        'labels' => $data['labels'],
                        'datasets' => [
                            ['label' => 'Actual', 'data' => $data['actual'], 'backgroundColor' => '#245c38', 'borderRadius' => 6],
                            ['label' => 'Target', 'data' => $data['target'], 'backgroundColor' => '#c9ddba', 'borderRadius' => 6],
                        ],
                    ],
                    'options' => ['responsive' => true, 'plugins' => ['legend' => ['position' => 'top']]],
                ],
            ];
        }
        return $charts;
    }

    public function indicators(): array
    {
        return $this->pdo->query("SELECT * FROM impact_indicators ORDER BY status = 'retired', code")->fetchAll();
    }

    /** @param array{status?:string,period?:string,geography?:string,program_id?:int,cohort_id?:int} $filters */
    public function results(?string $status = null, array $filters = []): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if (($filters['status'] ?? null) !== null && $status === null) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['period'] ?? '') !== '') {
            $where[] = 'r.period = ?';
            $params[] = $filters['period'];
        }
        if (($filters['geography'] ?? '') !== '') {
            $where[] = 'r.geography = ?';
            $params[] = $filters['geography'];
        }
        if (($filters['program_id'] ?? 0) > 0) {
            $where[] = 'r.program_id = ?';
            $params[] = $filters['program_id'];
        }
        if (($filters['cohort_id'] ?? 0) > 0) {
            $where[] = 'r.cohort_id = ?';
            $params[] = $filters['cohort_id'];
        }
        $sql = 'SELECT r.*, i.code, i.name AS indicator_name, i.unit, u.name AS submitted_by_name FROM impact_results r JOIN impact_indicators i ON i.id = r.indicator_id LEFT JOIN users u ON u.id = r.submitted_by';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY r.id DESC LIMIT 300');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function reports(): array
    {
        return $this->pdo->query('SELECT r.*, u.name AS created_by_name FROM impact_reports r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT 100')->fetchAll();
    }

    public function publishedReports(): array
    {
        return $this->pdo->query("SELECT * FROM impact_reports WHERE status = 'published' ORDER BY period DESC")->fetchAll();
    }

    public function report(int $id, bool $publicOnly = true): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM impact_reports WHERE id = ?' . ($publicOnly ? " AND status = 'published'" : ''));
        $statement->execute([$id]);
        $report = $statement->fetch();
        if ($report === false) {
            throw new HttpError(404, 'Report was not found.');
        }
        return $report;
    }

    /** CSV export of results for permitted staff (export.run). */
    public function exportResults(Actor $actor): string
    {
        $this->authorize($actor, 'export.run');
        $rows = $this->pdo->query("SELECT i.code, r.period, r.geography, r.program_id, r.cohort_id, r.value, i.unit, r.status, r.created_at FROM impact_results r JOIN impact_indicators i ON i.id = r.indicator_id ORDER BY r.period, i.code")->fetchAll();
        $stream = fopen('php://temp', 'r+b');
        \IEdify\Services\Exports\Csv::write($stream, ['code', 'period', 'geography', 'program_id', 'cohort_id', 'value', 'unit', 'status', 'created_at'], $rows);
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    private function transition(Actor $actor, int $resultId, int $expectedVersion, string $from, string $to, string $action, string $actorColumn, string $atColumn): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $resultId, $expectedVersion, $from, $to, $action, $actorColumn, $atColumn): void {
            $record = $this->locked($resultId, $expectedVersion);
            if ($actor->id === (int) $record['submitted_by']) {
                throw new HttpError(403, 'You cannot review your own result.');
            }
            if ($record['status'] !== $from) {
                throw new HttpError(409, "Only {$from} results can move to {$to}.");
            }
            $this->execute("UPDATE impact_results SET status = ?, {$actorColumn} = ?, {$atColumn} = UTC_TIMESTAMP(6), version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$to, $actor->id, $resultId]);
            $this->audit($actor, $action, $resultId);
        });
    }

    private function assertPeriod(string $period): void
    {
        if (!preg_match('~^\d{4}(-\d{2})?$~D', $period)) {
            throw new \InvalidArgumentException('Periods are YYYY or YYYY-MM.');
        }
    }

    private function locked(int $id, int $version): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM impact_results WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Result was not found.');
        }
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This record changed. Reload it before saving.');
        }
        return $record;
    }

    private function lockedReport(int $id, int $version): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM impact_reports WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Report was not found.');
        }
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This record changed. Reload it before saving.');
        }
        return $record;
    }

    private function record(string $table, int $id): void
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = ?");
        $statement->execute([$id]);
        if ($statement->fetchColumn() === false) {
            throw new HttpError(404, 'Record was not found.');
        }
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'impact', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

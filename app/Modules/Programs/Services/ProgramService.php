<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class ProgramService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createProgram(Actor $actor, string $slug, string $title, string $summary, string $eligibilitySummary): int
    {
        $this->authorize($actor, 'program.manage');
        $this->slug($slug);
        $this->title($title);
        return (new Transaction($this->pdo))->run(function () use ($actor, $slug, $title, $summary, $eligibilitySummary): int {
            $this->execute('INSERT INTO programs (slug, title, summary, eligibility_summary, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$slug, $title, $summary, $eligibilitySummary, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'program.created', (string) $id);
            return $id;
        });
    }

    public function setProgramStatus(Actor $actor, int $id, string $status, int $expectedVersion): void
    {
        $this->authorize($actor, 'program.manage');
        if (!in_array($status, ['draft', 'open', 'closed', 'archived'], true)) {
            throw new \InvalidArgumentException('Unknown program status.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $status, $expectedVersion): void {
            $this->locked('programs', $id, $expectedVersion);
            $this->execute('UPDATE programs SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $id]);
            $this->audit($actor, 'program.status.' . $status, (string) $id);
        });
    }

    public function createIntake(Actor $actor, int $programId, string $name, string $opensAt, string $closesAt, ?int $seats, array $eligibilityRules): int
    {
        $this->authorize($actor, 'program.manage');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~D', $opensAt) || !preg_match('~^\d{4}-\d{2}-\d{2}$~D', $closesAt) || $closesAt <= $opensAt) {
            throw new \InvalidArgumentException('Intake dates must be YYYY-MM-DD and closing after opening.');
        }
        $rules = (new FormDefinition())->validateRules($eligibilityRules);
        return (new Transaction($this->pdo))->run(function () use ($actor, $programId, $name, $opensAt, $closesAt, $seats, $rules): int {
            $this->record('programs', $programId);
            $this->execute('INSERT INTO intakes (program_id, name, opens_at, closes_at, seats, eligibility_rules, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$programId, $this->short($name), $opensAt, $closesAt, $seats, json_encode($rules, JSON_THROW_ON_ERROR)]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'intake.created', (string) $id);
            return $id;
        });
    }

    public function setIntakeStatus(Actor $actor, int $id, string $status, int $expectedVersion): void
    {
        $this->authorize($actor, 'program.manage');
        if (!in_array($status, ['draft', 'open', 'closed'], true)) {
            throw new \InvalidArgumentException('Unknown intake status.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $status, $expectedVersion): void {
            $this->locked('intakes', $id, $expectedVersion);
            $this->execute('UPDATE intakes SET status = ?, version = version + 1 WHERE id = ?', [$status, $id]);
            $this->audit($actor, 'intake.status.' . $status, (string) $id);
        });
    }

    public function createCohort(Actor $actor, int $programId, string $name, ?string $startsOn, ?string $endsOn): int
    {
        $this->authorize($actor, 'cohort.manage');
        return (new Transaction($this->pdo))->run(function () use ($actor, $programId, $name, $startsOn, $endsOn): int {
            $this->record('programs', $programId);
            $this->execute('INSERT INTO cohorts (program_id, name, starts_on, ends_on, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))', [$programId, $this->short($name), $startsOn, $endsOn]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'cohort.created', (string) $id);
            return $id;
        });
    }

    /** Publish a new versioned application form for an intake. */
    public function createForm(Actor $actor, int $intakeId, array $fields): int
    {
        $this->authorize($actor, 'program.manage');
        $schema = (new FormDefinition())->validate($fields);
        return (new Transaction($this->pdo))->run(function () use ($actor, $intakeId, $schema): int {
            $this->record('intakes', $intakeId);
            $statement = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM application_forms WHERE intake_id = ?');
            $statement->execute([$intakeId]);
            $version = (int) $statement->fetchColumn();
            $this->execute("UPDATE application_forms SET status = 'retired' WHERE intake_id = ? AND status = 'published'", [$intakeId]);
            $this->execute("INSERT INTO application_forms (intake_id, version, schema_json, status, created_by, created_at) VALUES (?, ?, ?, 'published', ?, UTC_TIMESTAMP(6))", [$intakeId, $version, json_encode($schema, JSON_THROW_ON_ERROR), $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'form.published', (string) $id, ['version' => $version]);
            return $id;
        });
    }

    /** Public catalogue view: open programs with currently-accepting intakes. */
    public function catalogue(): array
    {
        return $this->pdo->query("SELECT p.id, p.slug, p.title, p.summary, p.eligibility_summary, i.id AS intake_id, i.name AS intake_name, i.opens_at, i.closes_at, i.seats FROM programs p LEFT JOIN intakes i ON i.program_id = p.id AND i.status = 'open' AND i.opens_at <= UTC_TIMESTAMP(6) AND i.closes_at > UTC_TIMESTAMP(6) WHERE p.status = 'open' ORDER BY p.id")->fetchAll();
    }

    public function intake(int $id): array
    {
        return $this->record('intakes', $id);
    }

    public function program(int $id): array
    {
        return $this->record('programs', $id);
    }

    public function activeForm(int $intakeId): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM application_forms WHERE intake_id = ? AND status = 'published' ORDER BY version DESC LIMIT 1");
        $statement->execute([$intakeId]);
        $form = $statement->fetch();
        return $form === false ? null : $form;
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

    private function slug(string $slug): void
    {
        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || strlen($slug) > 191) {
            throw new \InvalidArgumentException('Slugs use lowercase words separated by hyphens.');
        }
    }

    private function title(string $title): void
    {
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('A title between 1 and 255 characters is required.');
        }
    }

    private function short(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180) {
            throw new \InvalidArgumentException('A name between 1 and 180 characters is required.');
        }
        return $name;
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, string $id, array $context = []): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'program', $id, $context);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

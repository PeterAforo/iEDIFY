<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Modules\Programs\Services\ApplicationService;
use IEdify\Modules\Programs\Services\FormDefinition;
use IEdify\Modules\Programs\Services\ProgramService;
use PHPUnit\Framework\TestCase;

final class ApplicationFlowTest extends TestCase
{
    private \PDO $pdo;
    private ProgramService $programs;
    private ApplicationService $applications;
    private Actor $manager;
    private Actor $reviewer;
    private Actor $applicant;
    private int $reviewerId;

    protected function setUp(): void
    {
        $this->pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $this->programs = new ProgramService($this->pdo);
        $this->applications = new ApplicationService($this->pdo);
        $this->manager = new Actor($this->user(), ['program.manage', 'application.decide', 'cohort.manage'], true, true, true);
        $this->reviewerId = $this->user();
        $this->reviewer = new Actor($this->reviewerId, ['application.review'], true, true, true);
        $this->applicant = new Actor($this->user(), ['application.read_own', 'application.submit'], true, false, true);
    }

    private function user(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'Flow user', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function openIntake(int $seats = 2): array
    {
        $programId = $this->programs->createProgram($this->manager, 'prog-' . bin2hex(random_bytes(6)), 'Seed Program', 'Summary', '18+ founders');
        $this->programs->setProgramStatus($this->manager, $programId, 'open', 1);
        $intakeId = $this->programs->createIntake($this->manager, $programId, 'Intake ' . bin2hex(random_bytes(4)), '2026-01-01', '2027-01-01', $seats, [
            ['field' => 'age', 'op' => 'gte', 'value' => 16, 'label' => 'Minimum age 16'],
        ]);
        $formId = $this->programs->createForm($this->manager, $intakeId, [
            ['key' => 'venture', 'label' => 'Venture name', 'type' => 'text', 'required' => true],
            ['key' => 'age', 'label' => 'Your age', 'type' => 'number', 'required' => true],
            ['key' => 'sector', 'label' => 'Sector', 'type' => 'select', 'options' => ['agriculture', 'technology', 'trade'], 'required' => true],
        ]);
        $this->programs->setIntakeStatus($this->manager, $intakeId, 'open', 1);
        return ['intake_id' => $intakeId, 'form_id' => $formId];
    }

    public function testApplicantSubmitsOnceAndWorkflowCompletes(): void
    {
        $setup = $this->openIntake();
        $cohortId = $this->programs->createCohort($this->manager, (int) $this->pdo->query('SELECT program_id FROM intakes WHERE id = ' . $setup['intake_id'])->fetchColumn(), 'Cohort A', '2026-06-01', '2026-09-01');
        $this->pdo->prepare('UPDATE intakes SET cohort_id = ? WHERE id = ?')->execute([$cohortId, $setup['intake_id']]);

        $applicationId = $this->applications->openDraft($this->applicant, $setup['intake_id']);
        self::assertSame($applicationId, $this->applications->openDraft($this->applicant, $setup['intake_id']), 'Re-opening returns the same draft.');

        $this->applications->saveAnswers($this->applicant, $applicationId, 1, ['venture' => 'Test venture', 'age' => '19']);
        try {
            $this->applications->submit($this->applicant, $applicationId, 2);
            self::fail('Missing required fields must block submission.');
        } catch (\InvalidArgumentException | HttpError $error) {
            self::assertTrue(true);
        }

        $this->applications->saveAnswers($this->applicant, $applicationId, 2, ['sector' => 'technology']);
        $this->applications->submit($this->applicant, $applicationId, 3);
        $application = $this->applications->findForApplicant($this->applicant, $applicationId);
        self::assertSame('submitted', $application['status']);
        self::assertNotNull($application['eligibility_snapshot'], 'Eligibility re-check must be frozen with the submission.');

        // Idempotent re-submit is a no-op, not an error or duplicate.
        $version = (int) $application['version'];
        $this->applications->submit($this->applicant, $applicationId, $version);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM application_status_history WHERE application_id = {$applicationId} AND to_status = 'submitted'")->fetchColumn());

        // In-app notification and queued acknowledgement exist.
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$this->applicant->id} AND type = 'application.submitted'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_events WHERE event_key = 'application.submitted.{$applicationId}'")->fetchColumn());

        // Reviewer cannot see unassigned applications.
        try {
            $this->applications->findForStaff($this->reviewer, $applicationId);
            self::fail('Unassigned reviewers must not read applications.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        // Workflow: screening → under_review → request_info → under_review → accepted → enrolled.
        $this->applications->assignReviewer($this->manager, $applicationId, $this->reviewerId);
        $application = $this->fresh($applicationId);
        $this->applications->transition($this->manager, $applicationId, 'screening', $version);
        $application = $this->fresh($applicationId);
        $this->applications->transition($this->manager, $applicationId, 'under_review', (int) $application['version']);
        $this->applications->recordReview($this->reviewer, $applicationId, 82, 'Strong traction.', 'Clear model.', 'accept');
        $application = $this->fresh($applicationId);
        $this->applications->transition($this->manager, $applicationId, 'request_info', (int) $application['version'], 'Please confirm your district.');
        $this->applications->respondToInfoRequest($this->applicant, $applicationId, (int) $this->fresh($applicationId)['version'], ['venture' => 'Test venture (updated)']);
        self::assertSame('under_review', $this->fresh($applicationId)['status']);

        $application = $this->fresh($applicationId);
        $this->applications->transition($this->manager, $applicationId, 'accepted', (int) $application['version']);
        $this->applications->enroll($this->manager, $applicationId, $cohortId, (int) $this->fresh($applicationId)['version']);
        self::assertSame('enrolled', $this->fresh($applicationId)['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM cohort_members WHERE cohort_id = {$cohortId} AND user_id = {$this->applicant->id}")->fetchColumn());
    }

    public function testEligibilityRulesRejectIneligibleApplicants(): void
    {
        $setup = $this->openIntake();
        $applicationId = $this->applications->openDraft($this->applicant, $setup['intake_id']);
        $this->applications->saveAnswers($this->applicant, $applicationId, 1, ['venture' => 'Too young', 'age' => '12', 'sector' => 'trade']);
        try {
            $this->applications->submit($this->applicant, $applicationId, 2);
            self::fail('Ineligible applicants must be rejected at submission.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
        }
        self::assertSame('draft', $this->fresh($applicationId)['status']);
    }

    public function testSeatCapacityBlocksConcurrentOverspendOfCohortPlaces(): void
    {
        $setup = $this->openIntake(1);
        $cohortId = $this->programs->createCohort($this->manager, (int) $this->pdo->query('SELECT program_id FROM intakes WHERE id = ' . $setup['intake_id'])->fetchColumn(), 'Cohort Cap', null, null);
        $ids = [];
        foreach ([1, 2] as $index) {
            $applicant = new Actor($this->user(), ['application.read_own', 'application.submit'], true, false, true);
            $applicationId = $this->applications->openDraft($applicant, $setup['intake_id']);
            $this->applications->saveAnswers($applicant, $applicationId, 1, ['venture' => 'V' . $index, 'age' => '20', 'sector' => 'trade']);
            $this->applications->submit($applicant, $applicationId, 2);
            $application = $this->fresh($applicationId);
            $this->applications->transition($this->manager, $applicationId, 'screening', (int) $application['version']);
            $application = $this->fresh($applicationId);
            $this->applications->transition($this->manager, $applicationId, 'under_review', (int) $application['version']);
            $application = $this->fresh($applicationId);
            $this->applications->transition($this->manager, $applicationId, 'accepted', (int) $application['version']);
            $ids[$index] = $applicationId;
        }
        $this->applications->enroll($this->manager, $ids[1], $cohortId, (int) $this->fresh($ids[1])['version']);
        try {
            $this->applications->enroll($this->manager, $ids[2], $cohortId, (int) $this->fresh($ids[2])['version']);
            self::fail('Seat capacity must block the second enrollment.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
        }
    }

    public function testWithdrawalClosesTheApplication(): void
    {
        $setup = $this->openIntake();
        $applicationId = $this->applications->openDraft($this->applicant, $setup['intake_id']);
        $this->applications->withdraw($this->applicant, $applicationId, 1);
        self::assertSame('withdrawn', $this->fresh($applicationId)['status']);
    }

    public function testFormDefinitionRejectsUnsafeSchemas(): void
    {
        $definition = new FormDefinition();
        try {
            $definition->validate([['key' => 'x', 'label' => 'X', 'type' => 'script', 'required' => false]]);
            self::fail('Arbitrary field types must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $definition->validateRules([['field' => 'age', 'op' => 'eval', 'value' => '1']]);
            self::fail('Arbitrary operators must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function fresh(int $applicationId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM applications WHERE id = ?');
        $statement->execute([$applicationId]);
        return $statement->fetch();
    }
}

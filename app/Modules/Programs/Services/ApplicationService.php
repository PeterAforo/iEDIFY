<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Application lifecycle. Statuses:
 * draft → submitted → screening → under_review → shortlisted|waitlisted|
 * request_info|accepted|rejected; accepted → enrolled; any open state →
 * withdrawn (applicant). request_info → under_review when the applicant
 * responds. Terminal: rejected, withdrawn, enrolled.
 */
final readonly class ApplicationService
{
    private const STAFF_TRANSITIONS = [
        'submitted' => ['screening'],
        'screening' => ['under_review', 'rejected'],
        'under_review' => ['shortlisted', 'waitlisted', 'request_info', 'accepted', 'rejected'],
        'shortlisted' => ['accepted', 'rejected'],
        'waitlisted' => ['accepted', 'rejected'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** Find or create the applicant's draft for an open intake. */
    public function openDraft(Actor $actor, int $intakeId): int
    {
        $this->authorize($actor, 'application.submit');
        return (new Transaction($this->pdo))->run(function () use ($actor, $intakeId): int {
            $existing = $this->pdo->prepare('SELECT id, status FROM applications WHERE intake_id = ? AND user_id = ? FOR UPDATE');
            $existing->execute([$intakeId, $actor->id]);
            $application = $existing->fetch();
            if ($application !== false) {
                return (int) $application['id'];
            }
            $intake = $this->openIntake($intakeId);
            $form = $this->pdo->prepare("SELECT id FROM application_forms WHERE intake_id = ? AND status = 'published' ORDER BY version DESC LIMIT 1");
            $form->execute([$intakeId]);
            $formId = $form->fetchColumn();
            if ($formId === false) {
                throw new HttpError(409, 'Applications are not being accepted for this intake yet.');
            }
            $this->execute("INSERT INTO applications (public_id, intake_id, form_id, user_id, answers, created_at, updated_at) VALUES (?, ?, ?, ?, '{}', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [bin2hex(random_bytes(16)), $intakeId, $formId, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->history($id, null, 'draft', $actor->id, null);
            $this->audit($actor, 'application.draft_opened', $id, ['reason_code' => 'intake:' . $intake['id']]);
            return $id;
        });
    }

    /** Save draft answers. Required fields are only enforced at submission. */
    public function saveAnswers(Actor $actor, int $applicationId, int $expectedVersion, array $answers): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $expectedVersion, $answers): void {
            $application = $this->owned($actor, $applicationId, $expectedVersion);
            if (!in_array($application['status'], ['draft', 'request_info'], true)) {
                throw new HttpError(409, 'Only draft applications can be edited.');
            }
            $fields = json_decode($this->formSchema((int) $application['form_id']), true, 512, JSON_THROW_ON_ERROR);
            $clean = (new FormDefinition())->validateAnswers($fields, $answers, false);
            $merged = array_merge(json_decode($application['answers'], true, 512, JSON_THROW_ON_ERROR), $clean);
            $this->execute('UPDATE applications SET answers = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [json_encode($merged, JSON_THROW_ON_ERROR), $applicationId]);
            $this->audit($actor, 'application.saved', $applicationId, ['version' => $expectedVersion + 1]);
        });
    }

    /** Record an uploaded document reference for a document field. */
    public function attachDocument(Actor $actor, int $applicationId, int $expectedVersion, string $fieldKey, int $mediaId): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $expectedVersion, $fieldKey, $mediaId): void {
            $application = $this->owned($actor, $applicationId, $expectedVersion);
            if (!in_array($application['status'], ['draft', 'request_info'], true)) {
                throw new HttpError(409, 'Only draft applications accept documents.');
            }
            $fields = json_decode($this->formSchema((int) $application['form_id']), true, 512, JSON_THROW_ON_ERROR);
            $field = null;
            foreach ($fields as $candidate) {
                if ($candidate['key'] === $fieldKey && $candidate['type'] === 'document') {
                    $field = $candidate;
                }
            }
            if ($field === null) {
                throw new HttpError(422, 'The document field is not part of this form.');
            }
            $media = $this->pdo->prepare("SELECT id FROM media_assets WHERE id = ? AND classification = 'private'");
            $media->execute([$mediaId]);
            if ($media->fetchColumn() === false) {
                throw new HttpError(422, 'The uploaded document is not available.');
            }
            $this->execute('INSERT INTO application_documents (application_id, field_key, media_id, uploaded_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE media_id = VALUES(media_id), uploaded_at = UTC_TIMESTAMP(6)', [$applicationId, $fieldKey, $mediaId]);
            $this->execute('UPDATE applications SET version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$applicationId]);
            $this->audit($actor, 'application.document_attached', $applicationId, ['version' => $expectedVersion + 1]);
        });
    }

    /**
     * Idempotent submission: re-checks the intake window, seat policy,
     * required answers and eligibility rules on the server. A repeat
     * submission of an already-submitted application is a no-op.
     */
    public function submit(Actor $actor, int $applicationId, int $expectedVersion): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $expectedVersion): void {
            $application = $this->owned($actor, $applicationId, $expectedVersion);
            if ($application['status'] === 'submitted') {
                return;
            }
            if ($application['status'] !== 'draft') {
                throw new HttpError(409, 'This application can no longer be submitted.');
            }
            $intake = $this->openIntake((int) $application['intake_id']);
            $fields = json_decode($this->formSchema((int) $application['form_id']), true, 512, JSON_THROW_ON_ERROR);
            $answers = json_decode($application['answers'], true, 512, JSON_THROW_ON_ERROR);
            $definition = new FormDefinition();
            $definition->validateAnswers($fields, $answers, true);
            $this->requireDocuments($applicationId, $fields);
            $unmet = $definition->unmetRules(json_decode($intake['eligibility_rules'], true, 512, JSON_THROW_ON_ERROR) ?? [], $this->context($actor->id, $answers));
            if ($unmet !== []) {
                throw new HttpError(422, 'Eligibility requirements were not met: ' . implode(', ', $unmet) . '.');
            }
            $snapshot = ['rules' => json_decode($intake['eligibility_rules'], true, 512, JSON_THROW_ON_ERROR), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->execute("UPDATE applications SET status = 'submitted', eligibility_snapshot = ?, submitted_at = UTC_TIMESTAMP(6), version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'draft'", [json_encode($snapshot, JSON_THROW_ON_ERROR), $applicationId]);
            $this->history($applicationId, 'draft', 'submitted', $actor->id, null);
            $this->notify($application['user_id'], 'application.submitted', 'Application received', 'Your application was received and will be screened. You will be contacted if more information is needed.', '/account/applications');
            (new Outbox($this->pdo))->record('application.submitted.' . $applicationId, 'notification.send', ['user_id' => (int) $application['user_id'], 'subject' => 'Application received', 'body' => 'Your application to ' . $intake['name'] . ' was received. Sign in to track its status.', 'link' => '/account/applications']);
            $this->audit($actor, 'application.submitted', $applicationId, ['version' => $expectedVersion + 1]);
        });
    }

    /** Staff transition along the documented workflow. */
    public function transition(Actor $actor, int $applicationId, string $toStatus, int $expectedVersion, ?string $note = null): void
    {
        $decisions = ['shortlisted', 'waitlisted', 'accepted', 'rejected'];
        $permission = in_array($toStatus, $decisions, true) ? 'application.decide' : 'program.manage';
        $this->authorize($actor, $permission);
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $toStatus, $expectedVersion, $note): void {
            $application = $this->locked($applicationId, $expectedVersion);
            $allowed = self::STAFF_TRANSITIONS[$application['status']] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new HttpError(409, "Applications cannot move from {$application['status']} to {$toStatus}.");
            }
            $note = $note !== null ? mb_substr(trim($note), 0, 1000) : null;
            if ($toStatus === 'request_info' && $note === null) {
                throw new HttpError(422, 'A request for information needs a message for the applicant.');
            }
            $this->execute('UPDATE applications SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$toStatus, $applicationId]);
            $this->history($applicationId, $application['status'], $toStatus, $actor->id, $note);
            $messages = [
                'request_info' => ['More information is needed', ($note ?? 'Please sign in and update your application.') . ''],
                'shortlisted' => ['Application shortlisted', 'Your application was shortlisted. Decisions will be shared after review.'],
                'waitlisted' => ['Application waitlisted', 'Your application is on the waitlist for this intake.'],
                'accepted' => ['Application accepted', 'Congratulations — your application was accepted. Next steps will follow.'],
                'rejected' => ['Application decision', 'Your application was not successful this time. Thank you for applying.'],
            ];
            if (isset($messages[$toStatus])) {
                [$subject, $body] = $messages[$toStatus];
                $this->notify((int) $application['user_id'], 'application.' . $toStatus, $subject, $body, '/account/applications');
                (new Outbox($this->pdo))->record('application.' . $toStatus . '.' . $applicationId . '.' . $expectedVersion, 'notification.send', ['user_id' => (int) $application['user_id'], 'subject' => $subject, 'body' => $body, 'link' => '/account/applications']);
            }
            $this->audit($actor, 'application.status.' . $toStatus, $applicationId, ['from_status' => $application['status'], 'to_status' => $toStatus, 'version' => $expectedVersion + 1]);
        });
    }

    /** Applicant responds to a request for information. */
    public function respondToInfoRequest(Actor $actor, int $applicationId, int $expectedVersion, array $answers): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $expectedVersion, $answers): void {
            $application = $this->owned($actor, $applicationId, $expectedVersion);
            if ($application['status'] !== 'request_info') {
                throw new HttpError(409, 'This application is not waiting for your response.');
            }
            $fields = json_decode($this->formSchema((int) $application['form_id']), true, 512, JSON_THROW_ON_ERROR);
            $clean = (new FormDefinition())->validateAnswers($fields, $answers, false);
            if ($clean === []) {
                throw new HttpError(422, 'Provide the requested information before resubmitting.');
            }
            $merged = array_merge(json_decode($application['answers'], true, 512, JSON_THROW_ON_ERROR), $clean);
            $this->execute("UPDATE applications SET answers = ?, status = 'under_review', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [json_encode($merged, JSON_THROW_ON_ERROR), $applicationId]);
            $this->history($applicationId, 'request_info', 'under_review', $actor->id, 'Applicant responded to the information request.');
            $this->audit($actor, 'application.info_responded', $applicationId, ['version' => $expectedVersion + 1]);
        });
    }

    /** Applicant withdraws a non-terminal application. */
    public function withdraw(Actor $actor, int $applicationId, int $expectedVersion): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $expectedVersion): void {
            $application = $this->owned($actor, $applicationId, $expectedVersion);
            if (in_array($application['status'], ['rejected', 'enrolled', 'withdrawn'], true)) {
                throw new HttpError(409, 'This application is already closed.');
            }
            $this->execute("UPDATE applications SET status = 'withdrawn', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$applicationId]);
            $this->history($applicationId, $application['status'], 'withdrawn', $actor->id, null);
            $this->audit($actor, 'application.withdrawn', $applicationId, ['from_status' => $application['status'], 'to_status' => 'withdrawn']);
        });
    }

    /** Enrol an accepted applicant into the intake's cohort. */
    public function enroll(Actor $actor, int $applicationId, int $cohortId, int $expectedVersion): void
    {
        $this->authorize($actor, 'cohort.manage');
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $cohortId, $expectedVersion): void {
            $application = $this->locked($applicationId, $expectedVersion);
            if ($application['status'] !== 'accepted') {
                throw new HttpError(409, 'Only accepted applications can be enrolled.');
            }
            $cohort = $this->pdo->prepare("SELECT c.*, i.seats FROM cohorts c JOIN intakes i ON i.program_id = c.program_id AND i.id = ? WHERE c.id = ? FOR UPDATE");
            $cohort->execute([(int) $application['intake_id'], $cohortId]);
            $record = $cohort->fetch();
            if ($record === false) {
                throw new HttpError(422, 'The cohort does not belong to this program.');
            }
            if ($record['seats'] !== null) {
                $count = $this->pdo->prepare("SELECT COUNT(*) FROM cohort_members WHERE cohort_id = ? AND status IN ('enrolled','active','completed')");
                $count->execute([$cohortId]);
                if ((int) $count->fetchColumn() >= (int) $record['seats']) {
                    throw new HttpError(409, 'This cohort has reached its seat capacity.');
                }
            }
            $this->execute("INSERT INTO cohort_members (cohort_id, user_id, application_id, enrolled_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE application_id = VALUES(application_id)", [$cohortId, (int) $application['user_id'], $applicationId]);
            $this->execute("UPDATE applications SET status = 'enrolled', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$applicationId]);
            $this->history($applicationId, 'accepted', 'enrolled', $actor->id, null);
            $this->notify((int) $application['user_id'], 'application.enrolled', 'Enrollment confirmed', 'You were enrolled in ' . $record['name'] . '. Your participant dashboard shows next steps.', '/account/applications');
            (new Outbox($this->pdo))->record('application.enrolled.' . $applicationId, 'notification.send', ['user_id' => (int) $application['user_id'], 'subject' => 'Enrollment confirmed', 'body' => 'You were enrolled in ' . $record['name'] . '.', 'link' => '/account/applications']);
            $this->audit($actor, 'application.enrolled', $applicationId, ['from_status' => 'accepted', 'to_status' => 'enrolled']);
        });
    }

    /** Assign a reviewer to an application. */
    public function assignReviewer(Actor $actor, int $applicationId, int $reviewerId): void
    {
        $this->authorize($actor, 'program.manage');
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $reviewerId): void {
            $application = $this->record($applicationId);
            if (in_array($application['status'], ['draft', 'withdrawn'], true)) {
                throw new HttpError(409, 'Reviewers can only be assigned to active applications.');
            }
            $this->execute('INSERT INTO review_assignments (application_id, reviewer_id, assigned_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)', [$applicationId, $reviewerId, $actor->id]);
            $this->notify($reviewerId, 'review.assigned', 'Application assigned for review', 'An application was assigned to you for review.', '/admin/applications/' . $applicationId);
            $this->audit($actor, 'application.reviewer_assigned', $applicationId, []);
        });
    }

    /** An assigned reviewer records a scorecard-style review. */
    public function recordReview(Actor $actor, int $applicationId, int $score, ?string $internalNotes, ?string $applicantFeedback, string $recommendation): void
    {
        $this->authorize($actor, 'application.review');
        if ($score < 0 || $score > 100 || !in_array($recommendation, ['shortlist', 'waitlist', 'accept', 'reject'], true)) {
            throw new \InvalidArgumentException('Score must be 0-100 with a valid recommendation.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $applicationId, $score, $internalNotes, $applicantFeedback, $recommendation): void {
            $assignment = $this->pdo->prepare('SELECT id FROM review_assignments WHERE application_id = ? AND reviewer_id = ?');
            $assignment->execute([$applicationId, $actor->id]);
            if ($assignment->fetchColumn() === false) {
                throw new HttpError(403, 'This application was not assigned to you.');
            }
            $application = $this->record($applicationId);
            if (!in_array($application['status'], ['under_review', 'screening'], true)) {
                throw new HttpError(409, 'This application is not open for review.');
            }
            $this->execute('INSERT INTO application_reviews (application_id, reviewer_id, score, internal_notes, applicant_feedback, recommendation, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE score = VALUES(score), internal_notes = VALUES(internal_notes), applicant_feedback = VALUES(applicant_feedback), recommendation = VALUES(recommendation)', [$applicationId, $actor->id, $score, $internalNotes, $applicantFeedback, $recommendation]);
            $this->execute('UPDATE review_assignments SET completed_at = UTC_TIMESTAMP(6) WHERE application_id = ? AND reviewer_id = ?', [$applicationId, $actor->id]);
            $this->audit($actor, 'application.reviewed', $applicationId, []);
        });
    }

    /** Applicant's own list. */
    public function forApplicant(Actor $actor): array
    {
        $this->authorize($actor, 'application.read_own');
        $statement = $this->pdo->prepare('SELECT a.*, i.name AS intake_name, p.title AS program_title FROM applications a JOIN intakes i ON i.id = a.intake_id JOIN programs p ON p.id = i.program_id WHERE a.user_id = ? ORDER BY a.id DESC');
        $statement->execute([$actor->id]);
        return $statement->fetchAll();
    }

    /** Staff list; reviewers only see assigned applications. */
    public function forStaff(Actor $actor, ?string $status = null): array
    {
        $sql = 'SELECT a.*, i.name AS intake_name, p.title AS program_title, u.email FROM applications a JOIN intakes i ON i.id = a.intake_id JOIN programs p ON p.id = i.program_id JOIN users u ON u.id = a.user_id';
        $where = [];
        $params = [];
        if ((new Policy())->allows($actor, 'application.review') && !(new Policy())->allows($actor, 'program.manage') && !(new Policy())->allows($actor, 'application.decide')) {
            $where[] = 'a.id IN (SELECT application_id FROM review_assignments WHERE reviewer_id = ?)';
            $params[] = $actor->id;
        } elseif (!$this->canStaff($actor)) {
            throw new HttpError(403, 'You do not have permission to view applications.');
        }
        $where[] = "a.status != 'draft'";
        if ($status !== null && in_array($status, ['submitted', 'screening', 'under_review', 'shortlisted', 'waitlisted', 'request_info', 'accepted', 'rejected', 'withdrawn', 'enrolled'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        $statement = $this->pdo->prepare($sql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 200');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function findForStaff(Actor $actor, int $applicationId): array
    {
        $application = $this->record($applicationId);
        if (!(new Policy())->allows($actor, 'program.manage') && !(new Policy())->allows($actor, 'application.decide')) {
            $assignment = $this->pdo->prepare('SELECT id FROM review_assignments WHERE application_id = ? AND reviewer_id = ?');
            $assignment->execute([$applicationId, $actor->id]);
            if ($assignment->fetchColumn() === false) {
                throw new HttpError(403, 'This application was not assigned to you.');
            }
        }
        $reviews = $this->pdo->prepare('SELECT r.*, u.name AS reviewer_name FROM application_reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.application_id = ?');
        $reviews->execute([$applicationId]);
        $history = $this->pdo->prepare('SELECT h.*, u.name AS actor_name FROM application_status_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.application_id = ? ORDER BY h.id');
        $history->execute([$applicationId]);
        $application['reviews'] = $reviews->fetchAll();
        $application['history'] = $history->fetchAll();
        return $application;
    }

    public function findForApplicant(Actor $actor, int $applicationId): array
    {
        $application = $this->record($applicationId);
        if ((int) $application['user_id'] !== $actor->id) {
            throw new HttpError(404, 'Application was not found.');
        }
        return $application;
    }

    public function formFields(int $formId): array
    {
        return json_decode($this->formSchema($formId), true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(int $userId, array $answers): array
    {
        $profile = $this->pdo->prepare('SELECT country, region, birth_year, gender FROM participant_profiles WHERE user_id = ?');
        $profile->execute([$userId]);
        $row = $profile->fetch() ?: [];
        return array_merge($row, $answers);
    }

    private function requireDocuments(int $applicationId, array $fields): void
    {
        foreach ($fields as $field) {
            if ($field['type'] === 'document' && $field['required']) {
                $statement = $this->pdo->prepare('SELECT COUNT(*) FROM application_documents WHERE application_id = ? AND field_key = ?');
                $statement->execute([$applicationId, $field['key']]);
                if ((int) $statement->fetchColumn() === 0) {
                    throw new HttpError(422, "{$field['label']} needs an uploaded document.");
                }
            }
        }
    }

    private function openIntake(int $intakeId): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM intakes WHERE id = ? FOR UPDATE");
        $statement->execute([$intakeId]);
        $intake = $statement->fetch();
        if ($intake === false) {
            throw new HttpError(404, 'Intake was not found.');
        }
        if ($intake['status'] !== 'open' || $intake['opens_at'] > gmdate('Y-m-d H:i:s') || $intake['closes_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new HttpError(409, 'This intake is not accepting applications.');
        }
        return $intake;
    }

    private function owned(Actor $actor, int $applicationId, int $expectedVersion): array
    {
        $application = $this->record($applicationId, true);
        if ((int) $application['user_id'] !== $actor->id) {
            throw new HttpError(404, 'Application was not found.');
        }
        if ((int) $application['version'] !== $expectedVersion) {
            throw new HttpError(409, 'This application changed. Reload it before continuing.');
        }
        return $application;
    }

    private function record(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM applications WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Application was not found.');
        }
        return $record;
    }

    private function locked(int $id, int $version): array
    {
        $record = $this->record($id, true);
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This application changed. Reload it before continuing.');
        }
        return $record;
    }

    private function formSchema(int $formId): string
    {
        $statement = $this->pdo->prepare('SELECT schema_json FROM application_forms WHERE id = ?');
        $statement->execute([$formId]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema)) {
            throw new HttpError(500, 'The application form is unavailable.');
        }
        return $schema;
    }

    private function history(int $applicationId, ?string $from, string $to, ?int $actorId, ?string $note): void
    {
        $this->execute('INSERT INTO application_status_history (application_id, from_status, to_status, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$applicationId, $from, $to, $actorId, $note]);
    }

    private function notify(int $userId, string $type, string $title, string $body, string $link): void
    {
        $statement = $this->pdo->prepare('SELECT enabled FROM notification_preferences WHERE user_id = ? AND scope = ?');
        $statement->execute([$userId, $type]);
        $enabled = $statement->fetchColumn();
        if ($enabled !== false && !(bool) $enabled) {
            return;
        }
        $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title), target_path = VALUES(target_path)', [$userId, $type . '.' . bin2hex(random_bytes(8)), $type, $title, $link]);
    }

    private function canStaff(Actor $actor): bool
    {
        $policy = new Policy();
        return $policy->allows($actor, 'program.manage') || $policy->allows($actor, 'application.decide') || $policy->allows($actor, 'application.review');
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id, array $metadata): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'application', (string) $id, $metadata);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

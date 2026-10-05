<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class LearningService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createCourse(Actor $actor, string $slug, string $title, string $summary): int
    {
        $this->authorize($actor, 'learning.manage');
        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || trim($title) === '') {
            throw new \InvalidArgumentException('A valid slug and title are required.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $slug, $title, $summary): int {
            $this->execute('INSERT INTO courses (slug, title, summary, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$slug, $title, $summary, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'course.created', $id);
            return $id;
        });
    }

    public function addLesson(Actor $actor, int $courseId, int $position, string $title, ?string $body, ?string $externalUrl): int
    {
        $this->authorize($actor, 'learning.manage');
        if ($position < 1 || $position > 500 || trim($title) === '') {
            throw new \InvalidArgumentException('Lesson needs a title and position 1-500.');
        }
        if ($externalUrl !== null && !preg_match('~^https://[a-z0-9.-]+(?::\d+)?/.*~iD', $externalUrl)) {
            throw new \InvalidArgumentException('External resources must be https links.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $courseId, $position, $title, $body, $externalUrl): int {
            $this->record('courses', $courseId);
            $this->execute('INSERT INTO course_lessons (course_id, position, title, body, external_url, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), external_url = VALUES(external_url)', [$courseId, $position, $title, $body, $externalUrl]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'lesson.saved', $id);
            return $id;
        });
    }

    public function setCourseStatus(Actor $actor, int $courseId, string $status): void
    {
        $this->authorize($actor, 'learning.manage');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('Unknown course status.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $courseId, $status): void {
            $this->record('courses', $courseId, true);
            $this->execute('UPDATE courses SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $courseId]);
            $this->audit($actor, 'course.status.' . $status, $courseId);
        });
    }

    /** Staff enrollment; cohort-scoped or individual. */
    public function enroll(Actor $actor, int $courseId, int $userId, ?int $cohortId = null): void
    {
        $this->authorize($actor, 'learning.manage');
        (new Transaction($this->pdo))->run(function () use ($actor, $courseId, $userId, $cohortId): void {
            $course = $this->record('courses', $courseId, true);
            if ($course['status'] !== 'published') {
                throw new HttpError(409, 'Only published courses accept enrollments.');
            }
            $this->execute("INSERT INTO course_enrollments (course_id, user_id, cohort_id, enrolled_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = 'enrolled', cohort_id = VALUES(cohort_id)", [$courseId, $userId, $cohortId]);
            $this->execute('INSERT INTO notifications (user_id, event_key, type, title, target_path, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title = VALUES(title)', [$userId, 'course.enrolled.' . $courseId . '.' . $userId, 'course.enrolled', 'You were enrolled in ' . $course['title'], '/learn']);
            $this->audit($actor, 'course.enrolled', $courseId);
        });
    }

    /** Enrolled participant marks a lesson complete; completion state is tracked. */
    public function completeLesson(Actor $actor, int $lessonId): void
    {
        $this->authorize($actor, 'learning.enrolled');
        (new Transaction($this->pdo))->run(function () use ($actor, $lessonId): void {
            $lesson = $this->pdo->prepare('SELECT l.course_id FROM course_lessons l WHERE l.id = ?');
            $lesson->execute([$lessonId]);
            $courseId = $lesson->fetchColumn();
            if ($courseId === false) {
                throw new HttpError(404, 'Lesson was not found.');
            }
            $enrollment = $this->pdo->prepare("SELECT status FROM course_enrollments WHERE course_id = ? AND user_id = ? AND status != 'withdrawn'");
            $enrollment->execute([$courseId, $actor->id]);
            if ($enrollment->fetchColumn() === false) {
                throw new HttpError(403, 'You are not enrolled in this course.');
            }
            $this->execute('INSERT INTO lesson_progress (lesson_id, user_id, completed_at) VALUES (?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE completed_at = VALUES(completed_at)', [$lessonId, $actor->id]);
            $remaining = $this->pdo->prepare('SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = ? AND NOT EXISTS (SELECT 1 FROM lesson_progress p WHERE p.lesson_id = l.id AND p.user_id = ?)');
            $remaining->execute([$courseId, $actor->id]);
            if ((int) $remaining->fetchColumn() === 0) {
                $this->execute("UPDATE course_enrollments SET status = 'completed', completed_at = UTC_TIMESTAMP(6) WHERE course_id = ? AND user_id = ? AND status = 'enrolled'", [$courseId, $actor->id]);
            }
            $this->audit($actor, 'lesson.completed', $lessonId);
        });
    }

    public function createSession(Actor $actor, int $courseId, string $label, string $sessionAt): int
    {
        $this->authorize($actor, 'learning.manage');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?~D', $sessionAt) || trim($label) === '') {
            throw new \InvalidArgumentException('A session needs a label and date/time.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $courseId, $label, $sessionAt): int {
            $this->record('courses', $courseId);
            $this->execute('INSERT INTO course_sessions (course_id, session_at, label, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$courseId, $sessionAt, $label]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'session.created', $id);
            return $id;
        });
    }

    public function recordAttendance(Actor $actor, int $sessionId, int $userId, string $status): void
    {
        $this->authorize($actor, 'learning.manage');
        if (!in_array($status, ['present', 'absent', 'excused'], true)) {
            throw new \InvalidArgumentException('Attendance is present, absent or excused.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $sessionId, $userId, $status): void {
            $session = $this->record('course_sessions', $sessionId);
            $enrollment = $this->pdo->prepare("SELECT id FROM course_enrollments WHERE course_id = ? AND user_id = ? AND status != 'withdrawn'");
            $enrollment->execute([$session['course_id'], $userId]);
            if ($enrollment->fetchColumn() === false) {
                throw new HttpError(422, 'Only enrolled participants can have attendance recorded.');
            }
            $this->execute('INSERT INTO attendance_records (session_id, user_id, status, recorded_by, recorded_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by), recorded_at = UTC_TIMESTAMP(6)', [$sessionId, $userId, $status, $actor->id]);
            $this->audit($actor, 'attendance.recorded', $sessionId);
        });
    }

    /** Courses the actor is enrolled in — participants only see their own. */
    public function enrolledCourses(Actor $actor): array
    {
        $this->authorize($actor, 'learning.enrolled');
        $statement = $this->pdo->prepare("SELECT c.*, e.status AS enrollment_status, e.enrolled_at, (SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = c.id) AS lesson_count, (SELECT COUNT(*) FROM lesson_progress p JOIN course_lessons l2 ON l2.id = p.lesson_id WHERE l2.course_id = c.id AND p.user_id = e.user_id) AS completed_count FROM course_enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? AND e.status != 'withdrawn' ORDER BY e.id DESC");
        $statement->execute([$actor->id]);
        return $statement->fetchAll();
    }

    /** Lessons with per-user completion, gated to enrolled participants or staff. */
    public function courseDetail(Actor $actor, int $courseId): array
    {
        $course = $this->record('courses', $courseId);
        $isStaff = (new Policy())->allows($actor, 'learning.manage');
        if (!$isStaff) {
            $enrollment = $this->pdo->prepare("SELECT status FROM course_enrollments WHERE course_id = ? AND user_id = ? AND status != 'withdrawn'");
            $enrollment->execute([$courseId, $actor->id]);
            if ($enrollment->fetchColumn() === false) {
                throw new HttpError(404, 'This course is not available.');
            }
        }
        $lessons = $this->pdo->prepare('SELECT l.*, (p.completed_at IS NOT NULL) AS done FROM course_lessons l LEFT JOIN lesson_progress p ON p.lesson_id = l.id AND p.user_id = ? WHERE l.course_id = ? ORDER BY l.position');
        $lessons->execute([$actor->id, $courseId]);
        $course['lessons'] = $lessons->fetchAll();
        return $course;
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

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'learning', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Modules\Community\Services\CommunityService;
use IEdify\Modules\Community\Services\EventService;
use IEdify\Modules\Learning\Services\LearningService;
use IEdify\Modules\Learning\Services\MentorshipService;
use IEdify\Modules\Learning\Services\MilestoneService;
use PHPUnit\Framework\TestCase;

final class PhaseCFlowTest extends TestCase
{
    private \PDO $pdo;
    private Actor $staff;
    private Actor $participant;
    private Actor $outsider;
    private int $participantId;
    private int $outsiderId;

    protected function setUp(): void
    {
        $this->pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $this->staff = new Actor($this->user(), ['learning.manage', 'mentoring.coordinate', 'program.manage', 'community.moderate'], true, true, true);
        $this->participantId = $this->user();
        $this->participant = new Actor($this->participantId, ['learning.enrolled', 'community.member'], true, false, true);
        $this->outsiderId = $this->user();
        $this->outsider = new Actor($this->outsiderId, ['learning.enrolled', 'community.member'], true, false, true);
    }

    private function user(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'User ' . substr($id, 0, 6), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testEnrolledTrainingCompletionAndAttendance(): void
    {
        $learning = new LearningService($this->pdo);
        $courseId = $learning->createCourse($this->staff, 'course-' . bin2hex(random_bytes(5)), 'Foundations', 'Intro');
        $learning->addLesson($this->staff, $courseId, 1, 'Lesson one', 'Body', null);
        $learning->addLesson($this->staff, $courseId, 2, 'Lesson two', 'Body', null);
        $learning->setCourseStatus($this->staff, $courseId, 'published');

        // Unenrolled participant cannot see course detail or mark lessons.
        try {
            $learning->courseDetail($this->participant, $courseId);
            self::fail('Unenrolled participants must not read course content.');
        } catch (HttpError $error) {
            self::assertSame(404, $error->status);
        }
        $lessonId = (int) $this->pdo->query("SELECT id FROM course_lessons WHERE course_id = {$courseId} LIMIT 1")->fetchColumn();
        try {
            $learning->completeLesson($this->participant, $lessonId);
            self::fail('Unenrolled completion must fail.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $learning->enroll($this->staff, $courseId, $this->participantId);
        $detail = $learning->courseDetail($this->participant, $courseId);
        self::assertCount(2, $detail['lessons']);
        $learning->completeLesson($this->participant, $lessonId);
        $otherLesson = (int) $this->pdo->query("SELECT id FROM course_lessons WHERE course_id = {$courseId} AND id != {$lessonId}")->fetchColumn();
        $learning->completeLesson($this->participant, $otherLesson);
        self::assertSame('completed', $this->pdo->query("SELECT status FROM course_enrollments WHERE course_id = {$courseId} AND user_id = {$this->participantId}")->fetchColumn());

        $sessionId = $learning->createSession($this->staff, $courseId, 'Kick-off', '2026-10-10 10:00');
        $learning->recordAttendance($this->staff, $sessionId, $this->participantId, 'present');
        self::assertSame('present', $this->pdo->query("SELECT status FROM attendance_records WHERE session_id = {$sessionId} AND user_id = {$this->participantId}")->fetchColumn());
        // Non-enrolled attendance is rejected.
        try {
            $learning->recordAttendance($this->staff, $sessionId, $this->outsiderId, 'present');
            self::fail('Attendance must require enrollment.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
        }
    }

    public function testMentorMatchAndSessionLifecycle(): void
    {
        $mentoring = new MentorshipService($this->pdo);
        $mentorId = $this->user();
        $mentor = new Actor($mentorId, ['mentoring.assigned', 'mentoring.schedule'], true, true, true);
        $mentoring->upsertProfile($mentor, 'Agribusiness', 'agriculture', 'Weekdays', 2, true);

        $matchId = $mentoring->proposeMatch($this->staff, $mentorId, $this->participantId);
        $mentoring->respondToMatch($this->participant, $matchId, 'accepted');
        $sessionId = $mentoring->scheduleSession($mentor, $matchId, '2026-12-01 14:00', 60, 'Kick-off', 'https://meet.example.invalid/abc');
        $mentoring->rescheduleSession($mentor, $sessionId, '2026-12-02 14:00');
        $mentoring->recordOutcome($mentor, $sessionId, 'Completed kick-off.', 'Participant shared plan.', 'shared');
        $session = $this->pdo->query("SELECT status FROM mentor_sessions WHERE id = {$sessionId}")->fetchColumn();
        self::assertSame('completed', $session);

        // An unrelated user cannot act on the match.
        try {
            $mentoring->scheduleSession($this->outsider, $matchId, '2026-12-05 10:00', 30, null, null);
            self::fail('Outsiders must not schedule sessions on others\' matches.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        // Private notes are hidden from the participant.
        $match2 = $mentoring->proposeMatch($this->staff, $mentorId, $this->outsiderId);
        $mentoring->respondToMatch($this->outsider, $match2, 'accepted');
        $private = $mentoring->scheduleSession($mentor, $match2, '2026-12-03 10:00', 30, null, null);
        $mentoring->recordOutcome($mentor, $private, 'Done.', 'Confidential note.', 'private');
        $sessions = $mentoring->sessionsFor($this->outsider);
        self::assertNull($sessions[0]['notes'], 'Private notes must be hidden from the participant.');
    }

    public function testMilestoneEvidenceAndStaffReview(): void
    {
        $milestones = new MilestoneService($this->pdo);
        $id = $milestones->create($this->staff, null, $this->participantId, 'Register business', 'Complete registration.', '2026-12-31');

        // Outsider cannot submit evidence on the participant's milestone.
        $outsider = new Actor($this->outsiderId, ['startup.team'], true, false, true);
        try {
            $milestones->submitEvidence($outsider, $id, 1, 'Fake evidence');
            self::fail('Outsiders must not submit evidence.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $milestones->submitEvidence($this->participant, $id, 1, 'Registration certificate filed.');
        self::assertSame('submitted', $this->pdo->query("SELECT status FROM milestones WHERE id = {$id}")->fetchColumn());
        $milestones->review($this->staff, $id, 2, 'approved');
        self::assertSame('approved', $this->pdo->query("SELECT status FROM milestones WHERE id = {$id}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$this->participantId} AND type = 'milestone.reviewed'")->fetchColumn());
    }

    public function testCommunityGroupsPostsReportsAndModeration(): void
    {
        $community = new CommunityService($this->pdo);
        $community->upsertProfile($this->participant, 'Participant P', null, 'technology', 'Accra', true);
        $community->upsertProfile($this->outsider, 'Outsider O', null, null, null, false);

        $publicGroup = $community->createGroup($this->staff, 'founders-' . bin2hex(random_bytes(4)), 'Founders', 'Public group', 'technology', 'public');
        $privateGroup = $community->createGroup($this->staff, 'private-' . bin2hex(random_bytes(4)), 'Private Circle', 'Private group', null, 'private');

        self::assertSame('active', $community->joinGroup($this->participant, $publicGroup));
        self::assertSame('pending', $community->joinGroup($this->participant, $privateGroup));

        // Pending member cannot post to the private group.
        try {
            $community->createPost($this->participant, $privateGroup, 'Hi', 'Body');
            self::fail('Pending members must not post.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        // Private group posts are invisible to non-members.
        $community->approveMember($this->staff, $privateGroup, $this->participantId, true);
        $postId = $community->createPost($this->participant, $privateGroup, 'Update', 'Confidential update');
        try {
            $community->post($this->outsider, $postId);
            self::fail('Private group posts must be invisible to outsiders.');
        } catch (HttpError $error) {
            self::assertSame(404, $error->status);
        }

        // Report → moderation removes the post; history is preserved.
        $community->report($this->outsider, 'post', $postId, 'Spam concern');
        $reportId = (int) $this->pdo->query('SELECT id FROM moderation_reports ORDER BY id DESC LIMIT 1')->fetchColumn();
        $community->moderate($this->staff, $reportId, 'remove', 'Confirmed');
        self::assertSame('removed', $this->pdo->query("SELECT status FROM community_posts WHERE id = {$postId}")->fetchColumn());
        self::assertSame('actioned', $this->pdo->query("SELECT status FROM moderation_reports WHERE id = {$reportId}")->fetchColumn());

        // Member discovery is opt-in only.
        $members = $community->discoverableMembers();
        $names = array_column($members, 'display_name');
        self::assertContains('Participant P', $names);
        self::assertNotContains('Outsider O', $names);
    }

    public function testEventRegistrationCapacityAndCancellation(): void
    {
        $events = new EventService($this->pdo);
        $eventId = $events->create($this->staff, 'demo-' . bin2hex(random_bytes(4)), 'Demo Day', 'Showcase', 'Accra', '2027-03-01 10:00', null, 1);
        $events->setStatus($this->staff, $eventId, 'published', 1);

        $events->register($this->participant, $eventId);
        $events->register($this->participant, $eventId); // idempotent
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = {$eventId} AND status = 'registered'")->fetchColumn());

        try {
            $events->register($this->outsider, $eventId);
            self::fail('Capacity must block the second registration.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
        }

        $events->cancel($this->participant, $eventId);
        $events->register($this->outsider, $eventId);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = {$eventId} AND status = 'registered' AND user_id = {$this->outsiderId}")->fetchColumn());
    }
}

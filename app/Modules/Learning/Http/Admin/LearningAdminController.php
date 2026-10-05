<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class LearningAdminController extends Controller
{
    public function index(): Response
    {
        $courses = $this->app->pdo()->query('SELECT c.*, (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = c.id) AS enrolled FROM courses c ORDER BY c.id DESC LIMIT 200')->fetchAll();
        return $this->render('admin/learning/index.twig', ['courses' => $courses]);
    }

    public function show(): Response
    {
        $id = (int) $this->vars['id'];
        $pdo = $this->app->pdo();
        $course = $this->app->learning()->courseDetail($this->requireActor(), $id);
        $sessions = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM attendance_records a WHERE a.session_id = s.id) AS recorded FROM course_sessions s WHERE s.course_id = ? ORDER BY s.session_at');
        $sessions->execute([$id]);
        $enrolled = $pdo->prepare("SELECT e.user_id, e.status, u.name, u.email FROM course_enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? ORDER BY u.name LIMIT 300");
        $enrolled->execute([$id]);
        return $this->render('admin/learning/course.twig', [
            'course' => $course,
            'sessions' => $sessions->fetchAll(),
            'enrolled' => $enrolled->fetchAll(),
        ]);
    }

    public function createCourse(): Response
    {
        try {
            $id = $this->app->learning()->createCourse($this->requireActor(), $this->input('slug'), $this->input('title'), $this->input('summary'));
            $this->flash('success', 'Course created.');
            return $this->redirect('/admin/courses/' . $id);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/learning');
        }
    }

    public function setStatus(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->learning()->setCourseStatus($this->requireActor(), $id, $this->input('status'));
            $this->flash('success', 'Course status updated.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/courses/' . $id);
    }

    public function addLesson(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->learning()->addLesson($this->requireActor(), $id, (int) $this->request->request->get('position'), $this->input('title'), $this->input('body') !== '' ? $this->input('body') : null, $this->input('external_url') !== '' ? $this->input('external_url') : null);
            $this->flash('success', 'Lesson saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/courses/' . $id);
    }

    public function addSession(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->learning()->createSession($this->requireActor(), $id, $this->input('label'), $this->input('session_at'));
            $this->flash('success', 'Session scheduled.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/courses/' . $id);
    }

    public function enroll(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $cohort = (int) $this->request->request->get('cohort_id');
            $this->app->learning()->enroll($this->requireActor(), $id, (int) $this->request->request->get('user_id'), $cohort > 0 ? $cohort : null);
            $this->flash('success', 'Participant enrolled.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/courses/' . $id);
    }

    public function attendance(): Response
    {
        try {
            $this->app->learning()->recordAttendance($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('user_id'), $this->input('status'));
            $this->flash('success', 'Attendance recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect($this->backPath('/admin/learning'));
    }

    public function milestones(): Response
    {
        $submitted = $this->app->pdo()->query("SELECT m.*, s.name AS startup_name, u.name AS owner_name FROM milestones m LEFT JOIN startups s ON s.id = m.startup_id LEFT JOIN users u ON u.id = m.user_id WHERE m.status = 'submitted' ORDER BY m.updated_at LIMIT 200")->fetchAll();
        return $this->render('admin/learning/milestones.twig', [
            'submitted' => $submitted,
            'overdue' => $this->app->milestones()->overdue(),
        ]);
    }

    public function reviewMilestone(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->milestones()->review($this->requireActor(), $id, (int) $this->request->request->get('version'), $this->input('decision'));
            $this->flash('success', 'Review recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/milestones');
    }

    private function backPath(string $fallback): string
    {
        $referer = $this->request->headers->get('Referer');
        if (is_string($referer)) {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/admin/')) {
                return $path;
            }
        }
        return $fallback;
    }
}

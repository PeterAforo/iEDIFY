<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationAdminController extends Controller
{
    public function index(): Response
    {
        $status = (string) $this->request->query->get('status', '');
        return $this->render('admin/applications/index.twig', [
            'applications' => $this->app->applications()->forStaff($this->requireActor(), $status !== '' ? $status : null),
            'status' => $status,
        ]);
    }

    public function show(): Response
    {
        $application = $this->app->applications()->findForStaff($this->requireActor(), (int) $this->vars['id']);
        $pdo = $this->app->pdo();
        $intake = $this->app->programs()->intake((int) $application['intake_id']);
        $fields = $this->app->applications()->formFields((int) $application['form_id']);
        $documents = $pdo->prepare('SELECT d.field_key, d.media_id, m.original_filename FROM application_documents d JOIN media_assets m ON m.id = d.media_id WHERE d.application_id = ?');
        $documents->execute([$application['id']]);
        $assignments = $pdo->prepare('SELECT r.reviewer_id, u.name, u.email, r.completed_at FROM review_assignments r JOIN users u ON u.id = r.reviewer_id WHERE r.application_id = ?');
        $assignments->execute([$application['id']]);
        $cohorts = $pdo->prepare('SELECT c.id, c.name FROM cohorts c WHERE c.program_id = ? ORDER BY c.id');
        $cohorts->execute([$intake['program_id']]);
        $reviewers = $pdo->prepare("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE p.name = 'application.review' ORDER BY u.name");
        $reviewers->execute();
        return $this->render('admin/applications/detail.twig', [
            'application' => $application,
            'intake' => $intake,
            'fields' => $fields,
            'answers' => json_decode($application['answers'], true, 512, JSON_THROW_ON_ERROR),
            'documents' => $documents->fetchAll(),
            'assignments' => $assignments->fetchAll(),
            'cohorts' => $cohorts->fetchAll(),
            'reviewers' => $reviewers->fetchAll(),
            'can_decide' => $this->app->policy()->allows($this->actor(), 'application.decide'),
            'can_manage' => $this->app->policy()->allows($this->actor(), 'program.manage'),
            'can_enroll' => $this->app->policy()->allows($this->actor(), 'cohort.manage'),
            'can_review' => $this->app->policy()->allows($this->actor(), 'application.review'),
        ]);
    }

    public function transition(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $application = $this->app->applications()->findForStaff($this->requireActor(), $id);
            $this->app->applications()->transition($this->requireActor(), $id, $this->input('status'), (int) $application['version'], $this->input('note') !== '' ? $this->input('note') : null);
            $this->flash('success', 'Status updated.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/applications/' . $id);
    }

    public function assign(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->applications()->assignReviewer($this->requireActor(), $id, (int) $this->request->request->get('reviewer_id'));
            $this->flash('success', 'Reviewer assigned.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/applications/' . $id);
    }

    public function review(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->applications()->recordReview(
                $this->requireActor(),
                $id,
                (int) $this->request->request->get('score'),
                $this->input('internal_notes') ?: null,
                $this->input('applicant_feedback') ?: null,
                $this->input('recommendation')
            );
            $this->flash('success', 'Review recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/applications/' . $id);
    }

    public function enroll(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $application = $this->app->applications()->findForStaff($this->requireActor(), $id);
            $this->app->applications()->enroll($this->requireActor(), $id, (int) $this->request->request->get('cohort_id'), (int) $application['version']);
            $this->flash('success', 'Applicant enrolled.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/applications/' . $id);
    }
}

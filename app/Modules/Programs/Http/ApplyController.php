<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\Programs\Services\DocumentStore;
use Symfony\Component\HttpFoundation\Response;

final class ApplyController extends Controller
{
    public function index(): Response
    {
        return $this->render('account/applications.twig', [
            'applications' => $this->app->applications()->forApplicant($this->requireActor()),
        ]);
    }

    public function form(): Response
    {
        $actor = $this->requireActor();
        $intakeId = (int) $this->vars['intakeId'];
        $applicationId = $this->app->applications()->openDraft($actor, $intakeId);
        return $this->detail($applicationId);
    }

    public function save(): Response
    {
        $actor = $this->requireActor();
        $intakeId = (int) $this->vars['intakeId'];
        $applicationId = $this->app->applications()->openDraft($actor, $intakeId);
        try {
            $answers = $this->request->request->all('answers');
            $application = $this->application($applicationId);
            $this->app->applications()->saveAnswers($actor, $applicationId, (int) $application['version'], $answers);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/apply/' . $intakeId);
        }
        $this->flash('success', 'Draft saved.');
        return $this->redirect('/apply/' . $intakeId);
    }

    public function submit(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $application = $this->application($id);
            $this->app->applications()->submit($this->requireActor(), $id, (int) $application['version']);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/apply/' . $this->intakeOf($id));
        }
        $this->flash('success', 'Application submitted. You will hear from the team by email and here.');
        return $this->redirect('/account/applications');
    }

    public function withdraw(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $application = $this->application($id);
            $this->app->applications()->withdraw($this->requireActor(), $id, (int) $application['version']);
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/account/applications');
        }
        $this->flash('success', 'Application withdrawn.');
        return $this->redirect('/account/applications');
    }

    public function respond(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $application = $this->application($id);
            $answers = $this->request->request->all('answers');
            $this->app->applications()->respondToInfoRequest($this->requireActor(), $id, (int) $application['version'], $answers);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/apply/' . $this->intakeOf($id));
        }
        $this->flash('success', 'Your response was sent back to the review team.');
        return $this->redirect('/account/applications');
    }

    public function uploadDocument(): Response
    {
        $actor = $this->requireActor();
        $id = (int) $this->vars['id'];
        $key = (string) $this->vars['key'];
        $application = $this->application($id);
        $file = $this->request->files->get('document');
        if ($file === null) {
            $this->flash('error', 'Choose a file to upload.');
            return $this->redirect('/apply/' . $this->intakeOf($id));
        }
        try {
            $mediaId = (new DocumentStore($this->app->pdo(), $this->app->config->path('CONTENT_STORAGE', $this->app->root . '/storage/private/content')))->store($file);
            $this->app->applications()->attachDocument($actor, $id, (int) $application['version'], $key, $mediaId);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/apply/' . $this->intakeOf($id));
        }
        $this->flash('success', 'Document uploaded.');
        return $this->redirect('/apply/' . $this->intakeOf($id));
    }

    private function detail(int $applicationId): Response
    {
        $application = $this->application($applicationId);
        $fields = $this->app->applications()->formFields((int) $application['form_id']);
        $documents = $this->app->pdo()->prepare('SELECT field_key, media_id FROM application_documents WHERE application_id = ?');
        $documents->execute([$applicationId]);
        return $this->render('applications/apply.twig', [
            'application' => $application,
            'intake' => $this->app->programs()->intake((int) $application['intake_id']),
            'fields' => $fields,
            'answers' => json_decode($application['answers'], true, 512, JSON_THROW_ON_ERROR),
            'documents' => array_column($documents->fetchAll(), 'media_id', 'field_key'),
        ]);
    }

    private function application(int $id): array
    {
        return $this->app->applications()->findForApplicant($this->requireActor(), $id);
    }

    private function intakeOf(int $applicationId): int
    {
        $statement = $this->app->pdo()->prepare('SELECT intake_id FROM applications WHERE id = ?');
        $statement->execute([$applicationId]);
        $intake = $statement->fetchColumn();
        return $intake === false ? 0 : (int) $intake;
    }
}

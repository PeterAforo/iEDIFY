<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class ProgramAdminController extends Controller
{
    public function index(): Response
    {
        $programs = $this->app->pdo()->query('SELECT p.*, (SELECT COUNT(*) FROM intakes i WHERE i.program_id = p.id) AS intakes FROM programs p ORDER BY p.id DESC LIMIT 200')->fetchAll();
        return $this->render('admin/programs/index.twig', ['programs' => $programs]);
    }

    public function create(): Response
    {
        try {
            $id = $this->app->programs()->createProgram(
                $this->requireActor(),
                $this->input('slug'),
                $this->input('title'),
                $this->input('summary'),
                $this->input('eligibility_summary')
            );
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/programs');
        }
        $this->flash('success', 'Program created.');
        return $this->redirect('/admin/programs/' . $id);
    }

    public function show(): Response
    {
        $program = $this->record('programs', (int) $this->vars['id']);
        $pdo = $this->app->pdo();
        $intakes = $pdo->prepare('SELECT * FROM intakes WHERE program_id = ? ORDER BY id DESC');
        $intakes->execute([$program['id']]);
        $cohorts = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM cohort_members m WHERE m.cohort_id = c.id) AS members FROM cohorts c WHERE c.program_id = ? ORDER BY c.id DESC');
        $cohorts->execute([$program['id']]);
        $forms = $pdo->prepare('SELECT f.* FROM application_forms f JOIN intakes i ON i.id = f.intake_id WHERE i.program_id = ? ORDER BY f.id DESC');
        $forms->execute([$program['id']]);
        return $this->render('admin/programs/detail.twig', [
            'program' => $program,
            'intakes' => $intakes->fetchAll(),
            'cohorts' => $cohorts->fetchAll(),
            'forms' => $forms->fetchAll(),
        ]);
    }

    public function setStatus(): Response
    {
        $program = $this->record('programs', (int) $this->vars['id']);
        try {
            $this->app->programs()->setProgramStatus($this->requireActor(), (int) $program['id'], $this->input('status'), (int) $this->request->request->get('version'));
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/programs/' . (int) $program['id']);
    }

    public function createIntake(): Response
    {
        $program = $this->record('programs', (int) $this->vars['id']);
        try {
            $rules = json_decode((string) $this->request->request->get('eligibility_rules', '[]'), true, 512, JSON_THROW_ON_ERROR);
            $seats = $this->input('seats');
            $this->app->programs()->createIntake(
                $this->requireActor(),
                (int) $program['id'],
                $this->input('name'),
                $this->input('opens_at'),
                $this->input('closes_at'),
                $seats === '' ? null : max(1, (int) $seats),
                is_array($rules) ? $rules : []
            );
        } catch (HttpError|\InvalidArgumentException|\JsonException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/programs/' . (int) $program['id']);
    }

    public function setIntakeStatus(): Response
    {
        $intake = $this->record('intakes', (int) $this->vars['id']);
        try {
            $this->app->programs()->setIntakeStatus($this->requireActor(), (int) $intake['id'], $this->input('status'), (int) $this->request->request->get('version'));
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/programs/' . (int) $intake['program_id']);
    }

    public function createForm(): Response
    {
        $intake = $this->record('intakes', (int) $this->vars['id']);
        try {
            $fields = json_decode((string) $this->request->request->get('fields', '[]'), true, 512, JSON_THROW_ON_ERROR);
            $this->app->programs()->createForm($this->requireActor(), (int) $intake['id'], is_array($fields) ? $fields : []);
            $this->flash('success', 'Form published.');
        } catch (HttpError|\InvalidArgumentException|\JsonException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/programs/' . (int) $intake['program_id']);
    }

    public function createCohort(): Response
    {
        $program = $this->record('programs', (int) $this->vars['id']);
        try {
            $this->app->programs()->createCohort(
                $this->requireActor(),
                (int) $program['id'],
                $this->input('name'),
                $this->input('starts_on') ?: null,
                $this->input('ends_on') ?: null
            );
            $this->flash('success', 'Cohort created.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/programs/' . (int) $program['id']);
    }

    private function record(string $table, int $id): array
    {
        $statement = $this->app->pdo()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Record was not found.');
        }
        return $record;
    }
}

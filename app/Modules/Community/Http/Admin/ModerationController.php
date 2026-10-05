<?php

declare(strict_types=1);

namespace IEdify\Modules\Community\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class ModerationController extends Controller
{
    public function index(): Response
    {
        $events = $this->app->pdo()->query('SELECT * FROM events ORDER BY starts_at DESC LIMIT 100')->fetchAll();
        return $this->render('admin/moderation/index.twig', [
            'reports' => $this->app->community()->openReports(),
            'events' => $this->app->policy()->allows($this->requireActor(), 'program.manage') ? $events : [],
        ]);
    }

    public function action(): Response
    {
        try {
            $this->app->community()->moderate($this->requireActor(), (int) $this->vars['id'], $this->input('action'), $this->input('note') !== '' ? $this->input('note') : null);
            $this->flash('success', 'Moderation action recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }

    public function createEvent(): Response
    {
        try {
            $capacity = $this->request->request->get('capacity');
            $this->app->events()->create($this->requireActor(), $this->input('slug'), $this->input('title'), $this->input('description'), $this->input('location'), $this->input('starts_at'), $this->input('ends_at') !== '' ? $this->input('ends_at') : null, is_numeric($capacity) ? (int) $capacity : null);
            $this->flash('success', 'Event created.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }

    public function eventStatus(): Response
    {
        try {
            $this->app->events()->setStatus($this->requireActor(), (int) $this->vars['id'], $this->input('status'), (int) $this->request->request->get('version'));
            $this->flash('success', 'Event status updated.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }
}

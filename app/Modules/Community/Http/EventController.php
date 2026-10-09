<?php

declare(strict_types=1);

namespace IEdify\Modules\Community\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class EventController extends Controller
{
    public function index(): Response
    {
        $cms = $this->cmsPage('/events');
        return $this->render('events/index.twig', [
            'events' => $this->app->events()->upcoming(),
            'past_events' => $this->app->events()->past(),
            'info' => $cms !== null ? \IEdify\Modules\Web\Services\PageLayouts::build('/events', $cms['sections']) : null,
            'media' => $cms['media'] ?? [],
        ]);
    }

    public function show(): Response
    {
        $event = $this->app->events()->findBySlug((string) $this->vars['slug']);
        if ($event === null) {
            throw new HttpError(404, 'Event was not found.');
        }
        $registered = false;
        $actor = $this->actor();
        if ($actor !== null) {
            $statement = $this->app->pdo()->prepare("SELECT status FROM event_registrations WHERE event_id = ? AND user_id = ? AND status = 'registered'");
            $statement->execute([(int) $event['id'], $actor->id]);
            $registered = $statement->fetchColumn() !== false;
        }
        return $this->render('events/show.twig', ['event' => $event, 'registered' => $registered]);
    }

    public function register(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->events()->register($this->requireActor(), $id);
            $this->flash('success', 'You are registered.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/events/' . $this->slugFor($id));
    }

    public function cancel(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->events()->cancel($this->requireActor(), $id);
            $this->flash('success', 'Registration cancelled.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/events/' . $this->slugFor($id));
    }

    public function opportunities(): Response
    {
        return $this->render('events/opportunities.twig', ['opportunities' => $this->app->community()->openOpportunities()]);
    }

    public function opportunity(): Response
    {
        return $this->render('events/opportunity.twig', ['opportunity' => $this->app->community()->opportunity((int) $this->vars['id'])]);
    }

    private function slugFor(int $eventId): string
    {
        $statement = $this->app->pdo()->prepare('SELECT slug FROM events WHERE id = ?');
        $statement->execute([$eventId]);
        $slug = $statement->fetchColumn();
        return is_string($slug) ? $slug : '';
    }
}

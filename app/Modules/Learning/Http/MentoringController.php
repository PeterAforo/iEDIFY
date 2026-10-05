<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class MentoringController extends Controller
{
    public function index(): Response
    {
        $actor = $this->requireActor();
        $mentoring = $this->app->mentoring();
        $profile = null;
        if ($this->app->policy()->allows($actor, 'mentoring.assigned')) {
            $statement = $this->app->pdo()->prepare('SELECT * FROM mentor_profiles WHERE user_id = ?');
            $statement->execute([$actor->id]);
            $profile = $statement->fetch() ?: null;
        }
        return $this->render('learning/mentoring.twig', [
            'matches' => $mentoring->matchesFor($actor),
            'sessions' => $mentoring->sessionsFor($actor),
            'profile' => $profile,
            'is_mentor' => $this->app->policy()->allows($actor, 'mentoring.assigned'),
            'can_coordinate' => $this->app->policy()->allows($actor, 'mentoring.coordinate'),
        ]);
    }

    public function saveProfile(): Response
    {
        try {
            $this->app->mentoring()->upsertProfile($this->requireActor(), $this->input('expertise'), $this->input('sectors'), $this->input('availability'), (int) $this->request->request->get('capacity'), $this->has('discoverable'));
            $this->flash('success', 'Mentor profile saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function respond(): Response
    {
        try {
            $this->app->mentoring()->respondToMatch($this->requireActor(), (int) $this->vars['id'], $this->input('decision'));
            $this->flash('success', 'Response recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function propose(): Response
    {
        try {
            $this->app->mentoring()->proposeMatch($this->requireActor(), (int) $this->request->request->get('mentor_id'), (int) $this->request->request->get('participant_id'));
            $this->flash('success', 'Match proposed.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function schedule(): Response
    {
        try {
            $this->app->mentoring()->scheduleSession($this->requireActor(), (int) $this->vars['id'], $this->input('scheduled_at'), (int) $this->request->request->get('duration'), $this->input('agenda') ?: null, $this->input('meeting_link') ?: null);
            $this->flash('success', 'Session scheduled.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function reschedule(): Response
    {
        try {
            $this->app->mentoring()->rescheduleSession($this->requireActor(), (int) $this->vars['id'], $this->input('scheduled_at'));
            $this->flash('success', 'Session rescheduled.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function cancel(): Response
    {
        try {
            $this->app->mentoring()->cancelSession($this->requireActor(), (int) $this->vars['id']);
            $this->flash('success', 'Session cancelled.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }

    public function complete(): Response
    {
        try {
            $this->app->mentoring()->recordOutcome($this->requireActor(), (int) $this->vars['id'], $this->input('outcome'), $this->input('notes') ?: null, $this->input('visibility') === 'shared' ? 'shared' : 'private');
            $this->flash('success', 'Outcome recorded.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/mentoring');
    }
}

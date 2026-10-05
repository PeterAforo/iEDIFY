<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class MilestoneController extends Controller
{
    public function index(): Response
    {
        return $this->render('learning/milestones.twig', [
            'milestones' => $this->app->milestones()->forActor($this->requireActor()),
        ]);
    }

    public function create(): Response
    {
        try {
            $startupId = $this->input('startup_id');
            $this->app->milestones()->create($this->requireActor(), $startupId !== '' ? (int) $startupId : null, (int) ($this->request->request->get('user_id') ?: $this->requireActor()->id), $this->input('title'), $this->input('description') ?: null, $this->input('due_on'));
            $this->flash('success', 'Milestone created.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/milestones');
    }

    public function evidence(): Response
    {
        $id = (int) $this->vars['id'];
        try {
            $this->app->milestones()->submitEvidence($this->requireActor(), $id, (int) $this->request->request->get('version'), $this->input('evidence'));
            $this->flash('success', 'Evidence submitted for review.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/account/milestones');
    }
}

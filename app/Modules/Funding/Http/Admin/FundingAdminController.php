<?php

declare(strict_types=1);

namespace IEdify\Modules\Funding\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class FundingAdminController extends Controller
{
    public function index(): Response
    {
        $funding = $this->app->funding();
        return $this->render('admin/funding/index.twig', [
            'rounds' => $funding->rounds(),
            'requests' => $funding->requestsForStaff(),
        ]);
    }

    public function round(): Response
    {
        $roundId = (int) $this->vars['id'];
        $round = $this->app->pdo()->prepare('SELECT * FROM funding_rounds WHERE id = ?');
        $round->execute([$roundId]);
        $round = $round->fetch();
        if ($round === false) {
            throw new HttpError(404, 'Round was not found.');
        }
        $rules = $this->app->pdo()->prepare('SELECT * FROM funding_rule_versions WHERE round_id = ? ORDER BY version DESC');
        $rules->execute([$roundId]);
        $requests = $this->app->pdo()->prepare('SELECT q.*, u.name AS applicant_name FROM funding_requests q JOIN users u ON u.id = q.user_id WHERE q.round_id = ? ORDER BY q.id DESC');
        $requests->execute([$roundId]);
        return $this->render('admin/funding/round.twig', ['round' => $round, 'rules' => $rules->fetchAll(), 'requests' => $requests->fetchAll()]);
    }

    public function request(): Response
    {
        return $this->render('admin/funding/request.twig', ['request' => $this->app->funding()->requestDetail((int) $this->vars['id'])]);
    }

    public function createRound(): Response
    {
        try {
            $maxAward = $this->request->request->get('max_award');
            $id = $this->app->funding()->createRound($this->requireActor(), (string) $this->request->request->get('slug', ''), (string) $this->request->request->get('title', ''), (string) $this->request->request->get('description', ''), strtoupper((string) $this->request->request->get('currency', 'USD')), $maxAward !== null && $maxAward !== '' ? (string) $maxAward : null, (string) $this->request->request->get('opens_at', ''), (string) $this->request->request->get('closes_at', ''));
            $this->flash('success', 'Funding round created.');
            return $this->redirect('/admin/funding/rounds/' . $id);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/funding');
        }
    }

    public function roundStatus(): Response
    {
        return $this->versioned('funding.rounds.' . (int) $this->vars['id'], '/admin/funding/rounds/' . (int) $this->vars['id'], fn () => $this->app->funding()->setRoundStatus($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('status', ''), (int) $this->request->request->get('version', 0)));
    }

    public function setForm(): Response
    {
        $roundId = (int) $this->vars['id'];
        try {
            $fields = json_decode((string) $this->request->request->get('fields', ''), true, 512, JSON_THROW_ON_ERROR);
            $this->app->funding()->setRoundForm($this->requireActor(), $roundId, $fields);
            $this->flash('success', 'Request form saved.');
        } catch (\JsonException) {
            $this->flash('error', 'Fields must be valid JSON.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/funding/rounds/' . $roundId);
    }

    public function createRules(): Response
    {
        $roundId = (int) $this->vars['id'];
        $weights = [];
        foreach ((array) $this->request->request->all('criteria') as $criterion => $weight) {
            $weights[$criterion] = $weight;
        }
        foreach (preg_split('/\R/', (string) $this->request->request->get('criteria_lines', '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_contains($line, '=')) {
                [$criterion, $weight] = explode('=', $line, 2);
                $weights[trim($criterion)] = trim($weight);
            }
        }
        try {
            $this->app->funding()->createRuleVersion($this->requireActor(), $roundId, $weights, (int) $this->request->request->get('max_score', 5));
            $this->flash('success', 'New rule version saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/funding/rounds/' . $roundId);
    }

    public function declareConflict(): Response
    {
        return $this->attempt('/admin/funding/requests/' . (int) $this->vars['id'], fn () => $this->app->funding()->declareConflict($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('declaration', '')), 'Conflict declared.');
    }

    public function clearConflict(): Response
    {
        return $this->attempt('/admin/funding', fn () => $this->app->funding()->clearConflict($this->requireActor(), (int) $this->vars['id']), 'Conflict cleared.');
    }

    public function review(): Response
    {
        $requestId = (int) $this->vars['id'];
        $scores = [];
        foreach ((array) $this->request->request->all('scores') as $criterion => $score) {
            $scores[$criterion] = $score;
        }
        return $this->attempt('/admin/funding/requests/' . $requestId, fn () => $this->app->funding()->recordReview($this->requireActor(), $requestId, $scores, (string) $this->request->request->get('recommendation', ''), $this->request->request->get('notes')), 'Review recorded.');
    }

    public function decide(): Response
    {
        return $this->attempt('/admin/funding/requests/' . (int) $this->vars['id'], fn () => $this->app->funding()->approve($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('decision', ''), $this->request->request->get('reason')), 'Decision recorded.');
    }

    public function award(): Response
    {
        return $this->attempt('/admin/funding/requests/' . (int) $this->vars['id'], fn () => $this->app->funding()->award($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('amount', ''), $this->request->request->get('conditions')), 'Award recorded.');
    }

    public function scheduleDisbursement(): Response
    {
        $awardId = (int) $this->vars['id'];
        $request = $this->app->pdo()->prepare('SELECT request_id FROM awards WHERE id = ?');
        $request->execute([$awardId]);
        $requestId = (int) ($request->fetchColumn() ?: 0);
        return $this->attempt('/admin/funding/requests/' . $requestId, fn () => $this->app->disbursements()->schedule($this->requireActor(), $awardId, (string) $this->request->request->get('reference', ''), (string) $this->request->request->get('scheduled_on', ''), (string) $this->request->request->get('amount', ''), $this->request->request->get('note')), 'Disbursement scheduled.');
    }

    public function authorizeDisbursement(): Response
    {
        return $this->disbursementAction('authorized', fn () => $this->app->disbursements()->authorize($this->requireActor(), (int) $this->vars['id']));
    }

    public function recordDisbursement(): Response
    {
        return $this->disbursementAction('recorded', fn () => $this->app->disbursements()->record($this->requireActor(), (int) $this->vars['id'], null, $this->request->request->get('note')));
    }

    public function cancelDisbursement(): Response
    {
        return $this->disbursementAction('cancelled', fn () => $this->app->disbursements()->cancel($this->requireActor(), (int) $this->vars['id']));
    }

    private function disbursementAction(string $label, callable $action): Response
    {
        $request = $this->app->pdo()->prepare('SELECT r.request_id FROM disbursements d JOIN awards r ON r.id = d.award_id WHERE d.id = ?');
        $request->execute([(int) $this->vars['id']]);
        $requestId = (int) ($request->fetchColumn() ?: 0);
        return $this->attempt('/admin/funding/requests/' . $requestId, $action, 'Disbursement ' . $label . '.');
    }

    private function versioned(string $key, string $redirect, callable $action): Response
    {
        return $this->attempt($redirect, $action, 'Saved.');
    }

    private function attempt(string $redirect, callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash('success', $success);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect($redirect);
    }
}

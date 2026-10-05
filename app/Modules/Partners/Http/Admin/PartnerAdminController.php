<?php

declare(strict_types=1);

namespace IEdify\Modules\Partners\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class PartnerAdminController extends Controller
{
    public function index(): Response
    {
        return $this->render('admin/partners/index.twig', ['orgs' => $this->app->partners()->orgs()]);
    }

    public function detail(): Response
    {
        $orgId = (int) $this->vars['id'];
        $reports = $this->app->pdo()->query("SELECT id, title, period FROM impact_reports WHERE status = 'published' ORDER BY period DESC")->fetchAll();
        $programs = $this->app->pdo()->query('SELECT id, title FROM programs ORDER BY title')->fetchAll();
        return $this->render('admin/partners/detail.twig', ['org' => $this->app->partners()->orgDetail($orgId), 'reports' => $reports, 'programs' => $programs]);
    }

    public function createOrg(): Response
    {
        return $this->attempt('/admin/partners', fn () => $this->app->partners()->createOrg($this->requireActor(), (string) $this->request->request->get('name', ''), (string) $this->request->request->get('org_type', 'partner'), $this->request->request->get('website') ?: null), 'Organization created.');
    }

    public function addMember(): Response
    {
        return $this->attempt($this->back(), function (): void {
            $userId = $this->app->pdo()->prepare('SELECT id FROM users WHERE email = ?');
            $userId->execute([mb_strtolower(trim((string) $this->request->request->get('email', '')))]);
            $userId = $userId->fetchColumn();
            if ($userId === false) {
                throw new \InvalidArgumentException('No account exists for that email.');
            }
            $this->app->partners()->addMember($this->requireActor(), (int) $this->vars['id'], (int) $userId, (string) $this->request->request->get('member_role', 'viewer'));
        }, 'Member added.');
    }

    public function suspendMember(): Response
    {
        return $this->attempt($this->back(), fn () => $this->app->partners()->suspendMember($this->requireActor(), (int) $this->vars['id'], (int) $this->vars['userId']), 'Member suspended.');
    }

    public function createProposal(): Response
    {
        return $this->attempt($this->back(), fn () => $this->app->partners()->createProposal($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('title', ''), (string) $this->request->request->get('summary', '')), 'Proposal drafted.');
    }

    public function proposalStatus(): Response
    {
        $proposal = $this->app->pdo()->prepare('SELECT org_id FROM partner_proposals WHERE id = ?');
        $proposal->execute([(int) $this->vars['id']]);
        $orgId = (int) ($proposal->fetchColumn() ?: 0);
        return $this->attempt('/admin/partners/' . $orgId, fn () => $this->app->partners()->transitionProposal($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('status', ''), (int) $this->request->request->get('version', 0)), 'Proposal updated.');
    }

    public function commitment(): Response
    {
        return $this->attempt($this->back(), function (): void {
            $proposalId = $this->request->request->get('proposal_id');
            $this->app->partners()->recordCommitment($this->requireActor(), (int) $this->vars['id'], $proposalId !== null && $proposalId !== '' ? (int) $proposalId : null, (string) $this->request->request->get('amount', ''), strtoupper((string) $this->request->request->get('currency', 'USD')), (string) $this->request->request->get('committed_on', ''), $this->request->request->get('conditions'));
        }, 'Commitment recorded.');
    }

    public function received(): Response
    {
        $commitment = $this->app->pdo()->prepare('SELECT org_id, currency FROM partner_commitments WHERE id = ?');
        $commitment->execute([(int) $this->vars['id']]);
        $row = $commitment->fetch();
        $orgId = (int) ($row['org_id'] ?? 0);
        $currency = (string) ($row['currency'] ?? 'USD');
        return $this->attempt('/admin/partners/' . $orgId, fn () => $this->app->partners()->recordReceived($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('reference', ''), (string) $this->request->request->get('amount', ''), $currency, (string) $this->request->request->get('received_on', ''), null), 'Funds received recorded.');
    }

    public function share(): Response
    {
        return $this->attempt($this->back(), fn () => $this->app->partners()->share($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('resource_type', ''), (int) $this->request->request->get('resource_id', 0)), 'Resource shared.');
    }

    public function revoke(): Response
    {
        return $this->attempt($this->back(), fn () => $this->app->partners()->revokeShare($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('resource_type', ''), (int) $this->request->request->get('resource_id', 0)), 'Share revoked.');
    }

    public function note(): Response
    {
        return $this->attempt($this->back(), fn () => $this->app->partners()->addNote($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('note', '')), 'Note saved.');
    }

    private function back(): string
    {
        return '/admin/partners/' . (int) $this->vars['id'];
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

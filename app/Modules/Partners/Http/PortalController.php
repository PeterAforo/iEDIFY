<?php

declare(strict_types=1);

namespace IEdify\Modules\Partners\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner/donor portal. Every resource resolves through canAccess() — an
 * active membership plus a live, unrevoked share — so guessed URLs, other
 * organizations' resources and revoked shares all return 404 rather than
 * leaking existence. Access is logged for accountability.
 */
final class PortalController extends Controller
{
    public function home(): Response
    {
        return $this->render('partner/index.twig', ['orgs' => $this->app->partners()->portalData($this->requireActor())]);
    }

    public function report(): Response
    {
        $actor = $this->requireActor();
        $reportId = (int) $this->vars['id'];
        $orgId = $this->app->partners()->canAccess($actor, 'report', $reportId);
        if ($orgId === null) {
            throw new HttpError(404, 'Report was not found.');
        }
        $this->app->partners()->logAccess($orgId, $actor, 'view', 'report', $reportId);
        return $this->render('partner/report.twig', ['report' => $this->app->impact()->report($reportId)]);
    }

    /** Shared document download; revoking the share revokes this endpoint. */
    public function download(): Response
    {
        $actor = $this->requireActor();
        $mediaId = (int) $this->vars['id'];
        $orgId = $this->app->partners()->canAccess($actor, 'document', $mediaId);
        if ($orgId === null) {
            throw new HttpError(404, 'Document was not found.');
        }
        $statement = $this->app->pdo()->prepare('SELECT * FROM media_assets WHERE id = ?');
        $statement->execute([$mediaId]);
        $media = $statement->fetch();
        if ($media === false) {
            throw new HttpError(404, 'Document was not found.');
        }
        $storageDir = $this->app->config->path('CONTENT_STORAGE', $this->app->root . '/storage/private/content');
        $path = $storageDir . '/' . basename((string) $media['storage_path']);
        if (!is_file($path)) {
            throw new HttpError(404, 'Document was not found.');
        }
        $this->app->partners()->logAccess($orgId, $actor, 'download', 'document', $mediaId);
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => (string) $media['mime'],
            'Content-Disposition' => 'attachment; filename="' . addslashes((string) $media['original_filename']) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function createProposal(): Response
    {
        try {
            $this->app->partners()->createProposal($this->requireActor(), (int) $this->request->request->get('org_id', 0), (string) $this->request->request->get('title', ''), (string) $this->request->request->get('summary', ''));
            $this->flash('success', 'Proposal drafted.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/partner');
    }

    public function proposalStatus(): Response
    {
        try {
            $this->app->partners()->transitionProposal($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('status', ''), (int) $this->request->request->get('version', 0));
            $this->flash('success', 'Proposal updated.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/partner');
    }

    public function addMember(): Response
    {
        try {
            $userId = $this->app->pdo()->prepare('SELECT id FROM users WHERE email = ?');
            $userId->execute([mb_strtolower(trim((string) $this->request->request->get('email', '')))]);
            $userId = $userId->fetchColumn();
            if ($userId === false) {
                throw new \InvalidArgumentException('No account exists for that email.');
            }
            $this->app->partners()->addMember($this->requireActor(), (int) $this->request->request->get('org_id', 0), (int) $userId, (string) $this->request->request->get('member_role', 'viewer'));
            $this->flash('success', 'Member added.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/partner');
    }
}

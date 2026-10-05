<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class ContentAdminController extends Controller
{
    public function index(): Response
    {
        $pdo = $this->app->pdo();
        $status = (string) $this->request->query->get('status', '');
        $type = (string) $this->request->query->get('type', '');
        $query = (string) $this->request->query->get('q', '');
        $sql = 'SELECT c.id, c.slug, c.title, c.content_type, c.status, c.working_state, c.version, c.updated_at, (SELECT COUNT(*) FROM editorial_flags f JOIN source_mappings m ON m.source_record_id = f.source_record_id WHERE m.content_id = c.id AND f.status = \'open\') AS open_flags FROM content_items c WHERE 1=1';
        $params = [];
        if (in_array($status, ['draft', 'review', 'published', 'archived'], true)) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        if (in_array($type, ['page', 'legal', 'team', 'program', 'pillar', 'news', 'story', 'faq', 'event', 'opportunity', 'publication', 'hero', 'auth_copy'], true)) {
            $sql .= ' AND c.content_type = ?';
            $params[] = $type;
        }
        if ($query !== '' && mb_strlen($query) <= 120) {
            $sql .= ' AND (c.title LIKE ? OR c.slug LIKE ?)';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        }
        $sql .= ' ORDER BY c.updated_at DESC LIMIT 200';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $this->render('admin/content/index.twig', [
            'items' => $statement->fetchAll(),
            'filters' => ['status' => $status, 'type' => $type, 'q' => $query],
        ]);
    }

    public function edit(): Response
    {
        $record = $this->record();
        $statement = $this->app->pdo()->prepare('SELECT * FROM content_revisions WHERE content_id = ? ORDER BY revision_number DESC LIMIT 1');
        $statement->execute([$record['id']]);
        $latest = $statement->fetch();
        $flags = $this->app->pdo()->prepare("SELECT f.id, f.code, f.message, f.status, f.created_at FROM editorial_flags f JOIN source_mappings m ON m.source_record_id = f.source_record_id WHERE m.content_id = ? ORDER BY f.created_at DESC");
        $flags->execute([$record['id']]);
        return $this->render('admin/content/edit.twig', [
            'item' => $record,
            'latest' => $latest === false ? null : $latest,
            'flags' => $flags->fetchAll(),
            'can_publish' => $this->app->policy()->allows($this->actor(), 'cms.publish'),
            'can_review' => $this->app->policy()->allows($this->actor(), 'cms.review'),
        ]);
    }

    public function revise(): Response
    {
        $record = $this->record();
        try {
            $sections = json_decode((string) $this->request->request->get('sections', ''), true, 512, JSON_THROW_ON_ERROR);
            $this->app->cms()->revise($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'), $this->input('title'), $sections);
        } catch (JsonException) {
            $this->flash('error', 'Sections must be valid JSON.');
            return $this->redirect('/admin/content/' . (int) $record['id']);
        } catch (InvalidArgumentException|HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . (int) $record['id']);
        }
        $this->flash('success', 'Draft saved.');
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    public function submitReview(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->submitReview($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . (int) $record['id']);
        }
        $this->flash('success', 'Submitted for review.');
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    public function publish(): Response
    {
        $record = $this->record();
        $latest = $this->app->pdo()->prepare('SELECT COALESCE(MAX(revision_number), 0) FROM content_revisions WHERE content_id = ?');
        $latest->execute([$record['id']]);
        try {
            $this->app->cms()->publish($this->requireActor(), (int) $record['id'], (int) $latest->fetchColumn(), (int) $this->request->request->get('version'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . (int) $record['id']);
        }
        $this->flash('success', 'Published.');
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    public function revisions(): Response
    {
        $record = $this->record();
        $statement = $this->app->pdo()->prepare('SELECT r.id, r.revision_number, r.title, r.created_at, u.name AS author, (r.id = c.published_revision_id) AS is_live FROM content_revisions r JOIN content_items c ON c.id = r.content_id LEFT JOIN users u ON u.id = r.author_id WHERE r.content_id = ? ORDER BY r.revision_number DESC');
        $statement->execute([$record['id']]);
        return $this->render('admin/content/revisions.twig', [
            'item' => $record,
            'revisions' => $statement->fetchAll(),
            'can_restore' => $this->app->policy()->allows($this->actor(), 'cms.restore'),
        ]);
    }

    public function restore(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->restore($this->requireActor(), (int) $record['id'], (int) $this->vars['number'], (int) $this->request->request->get('version'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . (int) $record['id'] . '/revisions');
        }
        $this->flash('success', 'Revision restored as a new draft.');
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    private function record(): array
    {
        $id = (int) $this->vars['id'];
        $statement = $this->app->pdo()->prepare('SELECT * FROM content_items WHERE id = ?');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Content was not found.');
        }
        return $record;
    }
}

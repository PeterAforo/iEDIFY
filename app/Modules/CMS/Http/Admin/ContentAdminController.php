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
    /** "Add new" form — pick a type, give a title and slug. */
    public function newForm(): Response
    {
        $type = (string) $this->request->query->get('type', 'page');
        return $this->render('admin/content/new.twig', [
            'types' => \IEdify\Modules\CMS\Services\ContentTypes::CREATABLE,
            'labels' => \IEdify\Modules\CMS\Services\ContentTypes::LABELS,
            'selected' => in_array($type, \IEdify\Modules\CMS\Services\ContentTypes::CREATABLE, true) ? $type : 'page',
        ]);
    }

    public function create(): Response
    {
        $type = (string) $this->request->request->get('type', 'page');
        if (!in_array($type, \IEdify\Modules\CMS\Services\ContentTypes::CREATABLE, true)) {
            $this->flash('error', 'Choose a content type you can create here. Team members are added from the Team screen.');
            return $this->redirect('/admin/content/new');
        }
        $slug = '/' . ltrim((string) $this->request->request->get('slug', ''), '/');
        $title = $this->input('title');
        try {
            $id = $this->app->cms()->create(
                $this->requireActor(),
                $type,
                $slug,
                $title,
                \IEdify\Modules\CMS\Services\ContentTypes::starterBlocks($type, $title),
            );
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/new?type=' . urlencode($type));
        }
        $this->flash('success', 'Created. You are editing the first draft.');
        return $this->redirect('/admin/content/' . $id);
    }

    /**
     * One-click save and publish for actors that hold cms.publish: revise,
     * submit for review and publish in sequence, re-reading the version
     * between steps. Flag/media gates still apply — a blocked item is
     * left in review and the error is surfaced.
     */
    public function savePublish(): Response
    {
        $record = $this->record();
        $actor = $this->requireActor();
        $id = (int) $record['id'];
        try {
            $sections = json_decode((string) $this->request->request->get('sections', ''), true, 512, JSON_THROW_ON_ERROR);
            $cms = $this->app->cms();
            $cms->revise($actor, $id, (int) $this->request->request->get('version'), $this->input('title'), $sections);
            $cms->submitReview($actor, $id, $this->version($id));
            $latest = $this->app->pdo()->prepare('SELECT COALESCE(MAX(revision_number), 0) FROM content_revisions WHERE content_id = ?');
            $latest->execute([$id]);
            $cms->publish($actor, $id, (int) $latest->fetchColumn(), $this->version($id));
        } catch (JsonException) {
            $this->flash('error', 'Sections must be valid JSON.');
            return $this->redirect('/admin/content/' . $id);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . $id);
        }
        $this->flash('success', 'Published.');
        return $this->redirect('/admin/content/' . $id);
    }

    public function archive(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->archive($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'));
            $this->flash('success', 'Archived — the page is no longer public.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    public function unarchive(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->unarchive($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'));
            $this->flash('success', 'Restored to draft.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/content/' . (int) $record['id']);
    }

    public function delete(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->delete($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'));
            $this->flash('success', 'Deleted permanently.');
            return $this->redirect('/admin/content');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/admin/content/' . (int) $record['id']);
        }
    }

    private function version(int $id): int
    {
        $statement = $this->app->pdo()->prepare('SELECT version FROM content_items WHERE id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

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
        $labels = \IEdify\Modules\CMS\Services\ContentTypes::LABELS;
        return $this->render('admin/content/index.twig', [
            'items' => $statement->fetchAll(),
            'status_counts' => $pdo->query('SELECT status, COUNT(*) AS total FROM content_items GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR),
            'filters' => ['status' => $status, 'type' => $type, 'q' => $query],
            'type_labels' => $labels,
            'creatable_types' => \IEdify\Modules\CMS\Services\ContentTypes::CREATABLE,
            'page_heading' => $type !== '' ? ($labels[$type] ?? ucfirst($type)) . 's' : 'All content',
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
            'preview_url' => $latest !== false ? '/preview/' . (int) $record['id'] . '/' . \IEdify\Modules\CMS\Services\CmsService::previewToken($this->app->config->string('APP_KEY'), (int) $record['id'], (int) $latest['id']) : null,
            'flags' => $flags->fetchAll(),
            'can_publish' => $this->app->policy()->allows($this->actor(), 'cms.publish'),
            'can_review' => $this->app->policy()->allows($this->actor(), 'cms.review'),
            'can_restore' => $this->app->policy()->allows($this->actor(), 'cms.restore'),
            'slot_hints' => \IEdify\Modules\CMS\Services\ContentTypes::slotHints((string) $record['slug']),
            'slot_headings' => \IEdify\Modules\CMS\Services\ContentTypes::slotHeadings((string) $record['slug']),
            'type_label' => \IEdify\Modules\CMS\Services\ContentTypes::label((string) $record['content_type']),
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

    public function saveMeta(): Response
    {
        $record = $this->record();
        try {
            $this->app->cms()->updateMeta($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'), (string) $this->request->request->get('category', ''), (string) $this->request->request->get('pub_year', ''));
            $this->flash('success', 'Metadata saved.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
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

    public function schedule(): Response
    {
        $record = $this->record();
        $at = trim((string) $this->request->request->get('publish_at', ''));
        try {
            $this->app->cms()->schedule($this->requireActor(), (int) $record['id'], (int) $this->request->request->get('version'), $at !== '' ? $at : null);
            $this->flash('success', $at !== '' ? 'Publication scheduled.' : 'Schedule cleared.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
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

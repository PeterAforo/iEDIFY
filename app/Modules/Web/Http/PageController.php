<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class PageController extends Controller
{
    public function home(): Response
    {
        return $this->page('/');
    }

    public function show(): Response
    {
        return $this->page('/' . (string) $this->vars['slug']);
    }

    private function page(string $slug): Response
    {
        $statement = $this->app->pdo()->prepare("SELECT c.id, c.slug, c.title, c.content_type, r.sections FROM content_items c JOIN content_revisions r ON r.content_id = c.id AND r.id = c.published_revision_id WHERE c.slug = ? AND c.status = 'published'");
        $statement->execute([$slug]);
        $page = $statement->fetch();
        if ($page === false) {
            if ($slug === '/' && $this->app->config->environment() !== 'production') {
                return $this->render('public/setup.twig');
            }
            throw new HttpError(404, 'This page is not available.');
        }
        $sections = json_decode($page['sections'], true, 512, JSON_THROW_ON_ERROR);
        $data = [
            'page' => $page,
            'sections' => $sections,
            'media' => $this->mediaUrls($sections),
        ];
        if ($slug === '/') {
            return $this->render('public/home.twig', $this->homeData($data));
        }
        if ($slug === '/team') {
            $data['roster'] = $this->roster();
            $portraits = array_values(array_filter(array_map(static fn (array $m): int => (int) $m['portrait_asset_id'], $data['roster'])));
            $data['media'] += $this->mediaByIds($portraits);
        }
        if ($slug === '/contact') {
            return $this->render('public/contact.twig', $data);
        }
        return $this->render('public/page.twig', $data);
    }

    /**
     * Signed draft preview: renders the latest revision regardless of status.
     * The HMAC token is issued only inside the admin UI, so access control is
     * the token itself — previews are noindex and never cached.
     */
    public function preview(): Response
    {
        $id = (int) $this->vars['id'];
        $revision = $this->app->pdo()->prepare('SELECT * FROM content_revisions WHERE content_id = ? ORDER BY revision_number DESC LIMIT 1');
        $revision->execute([$id]);
        $revision = $revision->fetch();
        $item = $this->app->pdo()->prepare('SELECT id, slug, title, content_type, status, working_state FROM content_items WHERE id = ?');
        $item->execute([$id]);
        $item = $item->fetch();
        if ($revision === false || $item === false) {
            throw new HttpError(404, 'This page is not available.');
        }
        $expected = \IEdify\Modules\CMS\Services\CmsService::previewToken($this->app->config->string('APP_KEY'), $id, (int) $revision['id']);
        if (!hash_equals($expected, (string) $this->vars['token'])) {
            throw new HttpError(404, 'This page is not available.');
        }
        $sections = json_decode($revision['sections'], true, 512, JSON_THROW_ON_ERROR);
        $data = [
            'page' => ['title' => $revision['title'] . ' (preview)', 'slug' => $item['slug'], 'content_type' => $item['content_type']],
            'sections' => $sections,
            'media' => $this->mediaUrls($sections),
            'preview' => ['status' => $item['status'], 'state' => $item['working_state'], 'revision' => (int) $revision['revision_number']],
        ];
        $response = $item['slug'] === '/'
            ? $this->render('public/home.twig', $this->homeData($data))
            : $this->render('public/page.twig', $data);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    /** Homepage view data: designed slots, hero slides and their media. */
    private function homeData(array $data): array
    {
        $data['home'] = \IEdify\Modules\Web\Services\HomeLayout::build($data['sections']);
        $data['hero_slides'] = (new \IEdify\Modules\CMS\Services\SiteChromeService($this->app->pdo()))->heroSlides();
        return $data;
    }

    /**
     * Resolve approved public media ids to URLs; unreviewed assets are omitted.
     */
    private function mediaUrls(array $sections): array
    {
        $ids = [];
        $walk = function (array $blocks) use (&$walk, &$ids): void {
            foreach ($blocks as $block) {
                if (isset($block['media_id'])) {
                    $ids[] = (int) $block['media_id'];
                }
                if (isset($block['items'])) {
                    $walk($block['items']);
                }
            }
        };
        $walk($sections);
        return $this->mediaByIds($ids);
    }

    /**
     * Approved public media lookup shared by section blocks and roster portraits.
     *
     * @param list<int> $ids
     */
    private function mediaByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->app->pdo()->prepare("SELECT id, width, height, mime, original_filename FROM media_assets WHERE id IN ({$placeholders}) AND classification = 'public_content' AND review_status = 'approved'");
        $statement->execute($ids);
        $media = [];
        foreach ($statement->fetchAll() as $row) {
            $media[(int) $row['id']] = [
                'url' => '/media/' . (int) $row['id'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'mime' => (string) $row['mime'],
                'filename' => (string) $row['original_filename'],
            ];
        }
        return $media;
    }

    private function roster(): array
    {
        return $this->app->pdo()->query("SELECT t.content_id, t.display_name, t.role_label, t.roster_group, t.youth_adviser, t.portrait_asset_id, c.slug FROM team_members t JOIN content_items c ON c.id = t.content_id AND c.status = 'published' ORDER BY t.roster_group, t.display_order")->fetchAll();
    }
}

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
        if ($slug === '/team') {
            $data['roster'] = $this->roster();
        }
        if ($slug === '/contact') {
            return $this->render('public/contact.twig', $data);
        }
        return $this->render('public/page.twig', $data);
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
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->app->pdo()->prepare("SELECT id FROM media_assets WHERE id IN ({$placeholders}) AND classification = 'public_content' AND review_status = 'approved'");
        $statement->execute($ids);
        $media = [];
        foreach ($statement->fetchAll() as $row) {
            $media[(int) $row['id']] = '/media/' . (int) $row['id'];
        }
        return $media;
    }

    private function roster(): array
    {
        return $this->app->pdo()->query("SELECT t.content_id, t.display_name, t.role_label, t.roster_group, t.youth_adviser, t.portrait_asset_id, c.slug FROM team_members t JOIN content_items c ON c.id = t.content_id AND c.status = 'published' ORDER BY t.roster_group, t.display_order")->fetchAll();
    }
}

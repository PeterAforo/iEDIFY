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
        if ($slug === '/about') {
            return $this->render('public/about.twig', $this->aboutData($data));
        }
        if ($slug === '/team') {
            return $this->render('public/team.twig', $this->teamData($data));
        }
        if (isset(self::INFO_TEMPLATES[$slug])) {
            return $this->render(self::INFO_TEMPLATES[$slug], $this->infoData($slug, $data));
        }
        return $this->render('public/page.twig', $data);
    }

    /** CMS pages rendered through designed editorial templates. */
    private const INFO_TEMPLATES = [
        '/programs' => 'public/programs.twig',
        '/impact' => 'impact/index.twig',
        '/contact' => 'public/contact.twig',
        '/community' => 'community/landing.twig',
        '/publications' => 'public/publications.twig',
    ];

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
        if ($item['slug'] === '/') {
            $response = $this->render('public/home.twig', $this->homeData($data));
        } elseif ($item['slug'] === '/about') {
            $response = $this->render('public/about.twig', $this->aboutData($data));
        } elseif ($item['slug'] === '/team') {
            $response = $this->render('public/team.twig', $this->teamData($data));
        } elseif (isset(self::INFO_TEMPLATES[$item['slug']])) {
            $response = $this->render(self::INFO_TEMPLATES[$item['slug']], $this->infoData($item['slug'], $data));
        } else {
            $response = $this->render('public/page.twig', $data);
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    /** Homepage view data: designed slots, hero slides and their media. */
    private function homeData(array $data): array
    {
        $data['home'] = \IEdify\Modules\Web\Services\HomeLayout::build($data['sections']);
        $data['hero_slides'] = (new \IEdify\Modules\CMS\Services\SiteChromeService($this->app->pdo()))->heroSlides();
        $data['media'] += $this->mediaByIds([9]); // 09_cta-team.webp: the strategy CTA image
        return $data;
    }

    /** Team view data: designed slots plus the roster grouped for display. */
    private function teamData(array $data): array
    {
        $data['team'] = \IEdify\Modules\Web\Services\TeamLayout::build($data['sections']);
        $data['roster'] = $this->roster();
        $portraits = array_values(array_filter(array_map(static fn (array $m): int => (int) $m['portrait_asset_id'], $data['roster'])));
        $data['media'] += $this->mediaByIds($portraits);
        return $data;
    }

    /**
     * About view data: designed slots and the admin-managed brand imagery
     * reused for the photo collage.
     */
    private function aboutData(array $data): array
    {
        $data['about'] = \IEdify\Modules\Web\Services\AboutLayout::build($data['sections']);
        $data['hero_slides'] = (new \IEdify\Modules\CMS\Services\SiteChromeService($this->app->pdo()))->heroSlides();
        $data['media'] += $this->mediaByIds([9]); // 09_cta-team.webp: the strategy CTA image
        return $data;
    }

    /**
     * Editorial page view data: designed slots plus the listing data some
     * templates expect when rendered through previews.
     */
    private function infoData(string $slug, array $data): array
    {
        $data['info'] = \IEdify\Modules\Web\Services\PageLayouts::build($slug, $data['sections']);
        if ($slug === '/impact') {
            $data['media'] += $this->mediaByIds([2]); // 02_hero-slide-youth.webp: story parallax backdrop
            $data += ['rows' => [], 'charts' => [], 'breakdown' => ['rows' => [], 'suppressed' => 0], 'reports' => [], 'threshold' => 5];
        }
        if ($slug === '/publications') {
            $data += ['items' => [], 'categories' => [], 'years' => [], 'filters' => ['q' => '', 'category' => '', 'year' => '']];
        }
        return $data;
    }

    private function roster(): array
    {
        return $this->app->pdo()->query("SELECT t.content_id, t.display_name, t.role_label, t.roster_group, t.youth_adviser, t.portrait_asset_id, c.slug FROM team_members t JOIN content_items c ON c.id = t.content_id AND c.status = 'published' ORDER BY t.roster_group, t.display_order")->fetchAll();
    }
}

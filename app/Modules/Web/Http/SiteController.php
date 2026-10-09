<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\Web\Services\GalleryAlbums;
use IEdify\Modules\Web\Services\PageLayouts;
use IEdify\Modules\Web\Services\SearchService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SiteController extends Controller
{
    public function search(): Response
    {
        $query = (string) $this->request->query->get('q', '');
        $page = (int) $this->request->query->get('page', 1);
        return $this->render('public/search.twig', ['query' => $query, 'search' => (new SearchService($this->app->pdo()))->publicSearch($query, $page)]);
    }

    /** Published news/story listing; detail pages resolve through the CMS slug route. */
    public function news(): Response
    {
        $items = $this->app->pdo()->query("SELECT title, slug, updated_at FROM content_items WHERE status = 'published' AND content_type IN ('news','story') ORDER BY updated_at DESC LIMIT 100")->fetchAll();
        $cms = $this->cmsPage('/news');
        return $this->render('public/news.twig', [
            'items' => $items,
            'info' => $cms !== null ? \IEdify\Modules\Web\Services\PageLayouts::build('/news', $cms['sections']) : null,
            'media' => $cms['media'] ?? [],
        ]);
    }

    /** Searchable publications with category and year filters; documents live on each page. */
    public function publications(): Response
    {
        $query = mb_substr(trim((string) $this->request->query->get('q', '')), 0, 120);
        $category = mb_substr(trim((string) $this->request->query->get('category', '')), 0, 60);
        $year = filter_var($this->request->query->get('year', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2200]]);
        $sql = "SELECT title, slug, category, pub_year, updated_at FROM content_items WHERE status = 'published' AND content_type = 'publication'";
        $params = [];
        if ($query !== '') {
            $sql .= ' AND title LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        }
        if ($category !== '') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($year !== false) {
            $sql .= ' AND pub_year = ?';
            $params[] = $year;
        }
        $statement = $this->app->pdo()->prepare($sql . ' ORDER BY pub_year DESC, title LIMIT 100');
        $statement->execute($params);
        $categories = $this->app->pdo()->query("SELECT DISTINCT category FROM content_items WHERE status = 'published' AND content_type = 'publication' AND category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);
        $years = $this->app->pdo()->query("SELECT DISTINCT pub_year FROM content_items WHERE status = 'published' AND content_type = 'publication' AND pub_year IS NOT NULL ORDER BY pub_year DESC")->fetchAll(\PDO::FETCH_COLUMN);
        $cms = $this->cmsPage('/publications');
        return $this->render('public/publications.twig', [
            'items' => $statement->fetchAll(),
            'categories' => $categories,
            'years' => $years,
            'filters' => ['q' => $query, 'category' => $category, 'year' => $year !== false ? $year : ''],
            'info' => $cms !== null ? \IEdify\Modules\Web\Services\PageLayouts::build('/publications', $cms['sections']) : null,
            'media' => $cms['media'] ?? [],
        ]);
    }

    /**
     * Event albums curated on the /gallery CMS page: each section chunk that
     * contains a gallery collection becomes an album. Before any albums are
     * curated the page falls back to a flat grid of all approved public media.
     */
    public function gallery(): Response
    {
        $cms = $this->cmsPage('/gallery');
        $sections = $cms['sections'] ?? [];
        $parsed = GalleryAlbums::collect($sections);
        $entries = $this->resolveEntries($parsed['entries']);
        $hasAlbums = count(array_filter($entries, static fn (array $e): bool => $e['type'] === 'album')) > 0;
        $info = $cms !== null ? PageLayouts::build('/gallery', $sections) : null;
        if ($info !== null) {
            $info['leftover'] = $parsed['extra'];
        }
        return $this->render('public/gallery.twig', [
            'entries' => $entries,
            'items' => !$hasAlbums ? $this->publicVisualMedia() : [],
            'info' => $info,
            'media' => $cms['media'] ?? [],
        ]);
    }

    /** A single event album — its photos and videos with lightbox navigation. */
    public function galleryAlbum(): Response
    {
        $slug = (string) $this->vars['album'];
        $cms = $this->cmsPage('/gallery');
        $parsed = GalleryAlbums::collect($cms['sections'] ?? []);
        $album = null;
        foreach ($parsed['entries'] as $candidate) {
            if (($candidate['type'] ?? null) === 'album' && $candidate['slug'] === $slug) {
                $album = $candidate;
                break;
            }
        }
        if ($album === null) {
            throw new HttpError(404, 'This album is not available.');
        }
        $media = $this->mediaByIds(array_map(static fn (array $item): int => (int) $item['media_id'], $album['items']));
        $items = [];
        foreach ($album['items'] as $item) {
            $id = (int) $item['media_id'];
            if (isset($media[$id])) {
                $items[] = $media[$id] + ['alt' => (string) $item['alt']];
            }
        }
        return $this->render('public/gallery-album.twig', [
            'album' => $album,
            'items' => $items,
        ]);
    }

    /**
     * Resolves album media ids in collage entries to approved public assets;
     * albums with no approved items are dropped. Cover prefers a still image.
     */
    private function resolveEntries(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'album') {
                continue;
            }
            foreach ($entry['items'] as $item) {
                $ids[] = (int) $item['media_id'];
            }
            $ids[] = $entry['cover_id'];
        }
        $media = $this->mediaByIds(array_values(array_unique(array_filter($ids))));
        $resolved = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'album') {
                $resolved[] = $entry;
                continue;
            }
            $album = $entry;
            $items = [];
            foreach ($album['items'] as $item) {
                $id = (int) $item['media_id'];
                if (isset($media[$id])) {
                    $items[] = $media[$id] + ['alt' => (string) $item['alt']];
                }
            }
            if ($items === []) {
                continue;
            }
            $cover = $media[$album['cover_id']] ?? null;
            if ($cover === null || str_starts_with((string) $cover['mime'], 'video/')) {
                $cover = null;
                foreach ($items as $item) {
                    if (!str_starts_with((string) $item['mime'], 'video/')) {
                        $cover = $item;
                        break;
                    }
                }
                $cover ??= $items[0];
            }
            $videos = count(array_filter($items, static fn (array $m): bool => str_starts_with((string) $m['mime'], 'video/')));
            $resolved[] = $album + [
                'cover' => $cover,
                'count' => count($items),
                'photos' => count($items) - $videos,
                'videos' => $videos,
            ];
        }
        return $resolved;
    }

    /** Flat listing of approved public photos/videos used before albums are curated. */
    private function publicVisualMedia(): array
    {
        return $this->app->pdo()->query("SELECT id, mime, width, height, alt_text, original_filename FROM media_assets WHERE classification = 'public_content' AND review_status = 'approved' AND (mime LIKE 'image/%' OR mime LIKE 'video/%') AND original_filename NOT LIKE '%logo%' ORDER BY id")->fetchAll();
    }

    /** Approved public documents — downloadable files from the media library. */
    public function resources(): Response
    {
        $items = $this->app->pdo()->query("SELECT id, mime, alt_text, original_filename FROM media_assets WHERE classification = 'public_content' AND review_status = 'approved' AND mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%' ORDER BY original_filename")->fetchAll();
        $cms = $this->cmsPage('/resources');
        return $this->render('public/resources.twig', [
            'items' => $items,
            'info' => $cms !== null ? \IEdify\Modules\Web\Services\PageLayouts::build('/resources', $cms['sections']) : null,
            'media' => $cms['media'] ?? [],
        ]);
    }

    /** CMS-managed FAQ with title search and an enquiry handoff. */
    public function faq(): Response
    {
        $query = mb_substr(trim((string) $this->request->query->get('q', '')), 0, 120);
        $sql = "SELECT title, slug, updated_at FROM content_items WHERE status = 'published' AND content_type = 'faq'";
        $params = [];
        if ($query !== '') {
            $sql .= ' AND title LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        }
        $statement = $this->app->pdo()->prepare($sql . ' ORDER BY title LIMIT 100');
        $statement->execute($params);
        return $this->render('public/faq.twig', ['items' => $statement->fetchAll(), 'q' => $query]);
    }

    /** Permanent redirects for captured legacy routes — no chains. */
    public function redirectAuth(): Response
    {
        $target = match ((string) $this->vars['page']) {
            'sign-in' => '/sign-in',
            'sign-up' => '/sign-up',
            default => '/',
        };
        return new RedirectResponse($target, 301);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /account\nDisallow: /partner\nDisallow: /learn\nDisallow: /community\nDisallow: /media\nDisallow: /sign-in\nDisallow: /register\nSitemap: " . rtrim($this->app->config->string('APP_URL'), '/') . "/sitemap.xml\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(): Response
    {
        $base = rtrim($this->app->config->string('APP_URL'), '/');
        $urls = ['/'];
        foreach ($this->app->pdo()->query("SELECT slug FROM content_items WHERE status = 'published'")->fetchAll() as $row) {
            $urls[] = (string) $row['slug'];
        }
        foreach ($this->app->pdo()->query("SELECT slug FROM events WHERE status = 'published'")->fetchAll() as $row) {
            $urls[] = '/events/' . $row['slug'];
        }
        $urls = array_values(array_unique(array_merge($urls, ['/programs', '/events', '/opportunities', '/impact', '/search'])));
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . $url, ENT_XML1) . "</loc></url>\n";
        }
        return new Response($xml . "</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}

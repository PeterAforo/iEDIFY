<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Http;

use IEdify\Core\Http\Controller;
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
        return $this->render('public/news.twig', ['items' => $items]);
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
        return $this->render('public/publications.twig', [
            'items' => $statement->fetchAll(),
            'categories' => $categories,
            'years' => $years,
            'filters' => ['q' => $query, 'category' => $category, 'year' => $year !== false ? $year : ''],
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

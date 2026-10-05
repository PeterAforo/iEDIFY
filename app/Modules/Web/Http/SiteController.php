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

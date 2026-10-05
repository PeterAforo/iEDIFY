<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Services;

use PDO;

/**
 * Public site search. Results are restricted to records a guest could open
 * directly — published CMS pages, published events and open opportunities —
 * so the endpoint enforces the same visibility rules as normal page access.
 * Private/community/member records are never indexed here.
 */
final readonly class SearchService
{
    private const PER_PAGE = 20;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{results: array<int, array{type: string, title: string, url: string, excerpt: string}>, total: int, page: int, pages: int} */
    public function publicSearch(string $query, int $page = 1): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $results = [];
        $this->collect($results, "SELECT 'page' AS type, c.title, c.slug AS url, LEFT(r.sections, 240) AS excerpt FROM content_items c JOIN content_revisions r ON r.id = c.published_revision_id WHERE c.status = 'published' AND (c.title LIKE ? ESCAPE '\\\\' OR r.sections LIKE ? ESCAPE '\\\\')", [$like, $like]);
        $this->collect($results, "SELECT 'event' AS type, title, CONCAT('/events/', slug) AS url, LEFT(description, 240) AS excerpt FROM events WHERE status = 'published' AND (title LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\')", [$like, $like]);
        $this->collect($results, "SELECT 'opportunity' AS type, title, CONCAT('/opportunities/', id) AS url, LEFT(summary, 240) AS excerpt FROM opportunities WHERE status = 'open' AND (title LIKE ? ESCAPE '\\\\' OR summary LIKE ? ESCAPE '\\\\')", [$like, $like]);
        $this->collect($results, "SELECT 'program' AS type, title, CONCAT('/programs/', slug) AS url, LEFT(summary, 240) AS excerpt FROM programs WHERE status = 'open' AND (title LIKE ? ESCAPE '\\\\' OR summary LIKE ? ESCAPE '\\\\')", [$like, $like]);

        $total = count($results);
        return [
            'results' => array_slice($results, $offset, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    private function collect(array &$results, string $sql, array $parameters): void
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT 500');
        $statement->execute($parameters);
        foreach ($statement->fetchAll() as $row) {
            $results[] = ['type' => $row['type'], 'title' => (string) $row['title'], 'url' => str_starts_with($row['url'], '/') ? $row['url'] : '/' . ltrim($row['url'], '/'), 'excerpt' => strip_tags((string) $row['excerpt'])];
        }
    }
}

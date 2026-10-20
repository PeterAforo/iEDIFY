<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $pdo = $this->app->pdo();
        $counts = [
            'content' => $pdo->query("SELECT status, COUNT(*) AS total FROM content_items GROUP BY status")->fetchAll(),
            'flags' => (int) $pdo->query("SELECT COUNT(*) FROM editorial_flags WHERE status = 'open'")->fetchColumn(),
            'media_pending' => (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE review_status = 'pending'")->fetchColumn(),
            'outbox_pending' => (int) $pdo->query("SELECT COUNT(*) FROM outbox_events WHERE status = 'pending'")->fetchColumn(),
            'team' => (int) $pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn(),
            'programs' => (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn(),
            'applications_open' => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('submitted','screening','under_review')")->fetchColumn(),
            'media_total' => (int) $pdo->query('SELECT COUNT(*) FROM media_assets')->fetchColumn(),
        ];
        $recent = $pdo->query('SELECT id, title, slug, content_type, status, updated_at FROM content_items ORDER BY updated_at DESC LIMIT 6')->fetchAll();
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Accra')))->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        return $this->render('admin/dashboard.twig', [
            'counts' => $counts,
            'recent' => $recent,
            'greeting' => $greeting,
        ]);
    }
}

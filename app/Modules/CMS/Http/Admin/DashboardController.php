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
        ];
        return $this->render('admin/dashboard.twig', ['counts' => $counts]);
    }
}

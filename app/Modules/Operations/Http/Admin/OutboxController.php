<?php

declare(strict_types=1);

namespace IEdify\Modules\Operations\Http\Admin;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Failed/pending outbox visibility for staff — retries are re-queued, never
 * re-executed inline, so the worker stays the only delivery path.
 */
final class OutboxController extends Controller
{
    public function index(): Response
    {
        $pdo = $this->app->pdo();
        $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM outbox_events GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $failed = $pdo->query('SELECT id, event_key, event_type, attempts, last_error_code, created_at, available_at FROM outbox_events WHERE status IN (\'failed\', \'pending\') ORDER BY status = \'pending\', id DESC LIMIT 200')->fetchAll();
        return $this->render('admin/outbox/index.twig', ['counts' => $counts, 'events' => $failed]);
    }

    public function retry(): Response
    {
        $id = (int) $this->vars['id'];
        (new Transaction($this->app->pdo()))->run(function () use ($id): void {
            $updated = $this->app->pdo()->prepare("UPDATE outbox_events SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(6), last_error_code = NULL, lease_token = NULL, lease_until = NULL WHERE id = ? AND status = 'failed'");
            $updated->execute([$id]);
            if ($updated->rowCount() === 0) {
                throw new \InvalidArgumentException('Only failed events can be re-queued.');
            }
            (new AuditLog($this->app->pdo()))->record($this->requireActor()->id, 'outbox.retried', 'outbox_events', (string) $id);
        });
        $this->flash('success', 'Event re-queued for delivery.');
        return $this->redirect('/admin/outbox');
    }
}

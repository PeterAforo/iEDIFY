<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class FlagController extends Controller
{
    public function index(): Response
    {
        $statement = $this->app->pdo()->query("SELECT f.id, f.code, f.message, f.status, f.created_at, s.source_key, m.content_id, c.title AS content_title FROM editorial_flags f JOIN source_records s ON s.id = f.source_record_id LEFT JOIN source_mappings m ON m.source_record_id = s.id LEFT JOIN content_items c ON c.id = m.content_id WHERE f.status = 'open' ORDER BY f.created_at");
        return $this->render('admin/flags.twig', ['flags' => $statement->fetchAll()]);
    }

    public function resolve(): Response
    {
        $id = (int) $this->vars['id'];
        $actor = $this->requireActor();
        (new Transaction($this->app->pdo()))->run(function () use ($id, $actor): void {
            $statement = $this->app->pdo()->prepare("SELECT id, status FROM editorial_flags WHERE id = ? FOR UPDATE");
            $statement->execute([$id]);
            $flag = $statement->fetch();
            if ($flag === false) {
                throw new HttpError(404, 'Flag was not found.');
            }
            $this->app->pdo()->prepare("UPDATE editorial_flags SET status = 'resolved' WHERE id = ? AND status = 'open'")->execute([$id]);
            (new AuditLog($this->app->pdo()))->record($actor->id, 'cms.flag_resolved', 'editorial_flag', (string) $id);
        });
        $this->flash('success', 'Flag resolved.');
        return $this->redirect('/admin/flags');
    }
}

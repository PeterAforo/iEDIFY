<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Http\Admin;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class MediaAdminController extends Controller
{
    public function index(): Response
    {
        $status = (string) $this->request->query->get('status', 'pending');
        $sql = 'SELECT id, original_filename, mime, width, height, alt_text, classification, review_status, created_at FROM media_assets';
        $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $sql .= ' WHERE review_status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        return $this->render('admin/media/index.twig', ['assets' => $statement->fetchAll(), 'status' => $status]);
    }

    public function review(): Response
    {
        $id = (int) $this->vars['id'];
        $decision = (string) $this->request->request->get('decision');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpError(422, 'Choose approve or reject.');
        }
        $actor = $this->requireActor();
        (new Transaction($this->app->pdo()))->run(function () use ($id, $decision, $actor): void {
            $statement = $this->app->pdo()->prepare('SELECT id, review_status FROM media_assets WHERE id = ? FOR UPDATE');
            $statement->execute([$id]);
            $asset = $statement->fetch();
            if ($asset === false) {
                throw new HttpError(404, 'Media was not found.');
            }
            if ($asset['review_status'] !== 'pending') {
                throw new HttpError(409, 'This media was already reviewed.');
            }
            $this->app->pdo()->prepare('UPDATE media_assets SET review_status = ? WHERE id = ?')->execute([$decision, $id]);
            (new AuditLog($this->app->pdo()))->record($actor->id, 'media.' . $decision, 'media', (string) $id);
        });
        $this->flash('success', 'Media ' . $decision . '.');
        return $this->redirect('/admin/media');
    }
}

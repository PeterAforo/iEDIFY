<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class MediaController extends Controller
{
    public function serve(): Response
    {
        $id = (int) $this->vars['id'];
        $statement = $this->app->pdo()->prepare('SELECT * FROM media_assets WHERE id = ?');
        $statement->execute([$id]);
        $asset = $statement->fetch();
        if ($asset === false) {
            throw new HttpError(404, 'This file is not available.');
        }
        $approved = $asset['classification'] === 'public_content' && $asset['review_status'] === 'approved';
        if (!$approved) {
            $actor = $this->app->actor();
            if (!$this->app->policy()->allows($actor, 'media.manage')) {
                throw new HttpError(404, 'This file is not available.');
            }
        }
        $directory = $this->app->config->string('CONTENT_STORAGE', $this->app->root . '/storage/private/content');
        $path = realpath($directory . '/' . basename($asset['storage_path']));
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', realpath($directory) ?: $directory))) {
            throw new HttpError(404, 'This file is not available.');
        }
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $asset['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $approved ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
        $response->headers->set('Content-Disposition', 'inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $asset['original_filename']) . '"');
        return $response;
    }
}

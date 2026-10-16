<?php

declare(strict_types=1);

namespace IEdify\Modules\Web\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\CMS\Services\ImageProcessor;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class MediaController extends Controller
{
    public function serve(): Response
    {
        [$asset, $approved] = $this->asset((int) $this->vars['id']);
        $path = $this->storedPath($asset);
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $asset['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $approved ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
        $response->headers->set('Content-Disposition', 'inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $asset['original_filename']) . '"');
        return $response->prepare($this->request);
    }

    /**
     * Fixed-width rendition for responsive srcset. The rendition file is
     * generated on demand when absent and falls back to the original when
     * the source cannot be resampled. Images only - other media 404s.
     */
    public function serveRendition(): Response
    {
        $width = (int) $this->vars['w'];
        if (!in_array($width, ImageProcessor::WIDTHS, true)) {
            throw new HttpError(404, 'This file is not available.');
        }
        [$asset, $approved] = $this->asset((int) $this->vars['id']);
        if (!str_starts_with((string) $asset['mime'], 'image/')) {
            throw new HttpError(404, 'This file is not available.');
        }
        $directory = $this->app->config->path('CONTENT_STORAGE', $this->app->root . '/storage/private/content');
        $hash = pathinfo((string) $asset['storage_path'], PATHINFO_FILENAME);
        $path = ImageProcessor::renditionPath($directory, $hash, $width);
        if ($path !== null && !is_file($path)) {
            try {
                (new ImageProcessor())->generate($directory . '/' . basename($asset['storage_path']));
            } catch (\Throwable) {
            }
        }
        if ($path === null || !is_file($path)) {
            return $this->serve();
        }
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => ImageProcessor::renditionMime($path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $approved ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
        $response->headers->set('Content-Disposition', 'inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $asset['original_filename']) . '"');
        return $response->prepare($this->request);
    }

    /** @return array{0: array, 1: bool} the asset row and whether it is approved public content */
    private function asset(int $id): array
    {
        $statement = $this->app->pdo()->prepare('SELECT * FROM media_assets WHERE id = ?');
        $statement->execute([$id]);
        $asset = $statement->fetch();
        if ($asset === false) {
            throw new HttpError(404, 'This file is not available.');
        }
        $approved = $asset['classification'] === 'public_content' && $asset['review_status'] === 'approved';
        if (!$approved && !$this->maySeePrivate((int) $asset['id'])) {
            throw new HttpError(404, 'This file is not available.');
        }
        return [$asset, $approved];
    }

    private function storedPath(array $asset): string
    {
        $directory = $this->app->config->path('CONTENT_STORAGE', $this->app->root . '/storage/private/content');
        $path = realpath($directory . '/' . basename($asset['storage_path']));
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', realpath($directory) ?: $directory))) {
            throw new HttpError(404, 'This file is not available.');
        }
        return $path;
    }

    /**
     * Private assets (application documents, milestone evidence) are visible to
     * media managers, program staff, the owning applicant and their assigned
     * reviewers, and to milestone owners/startup members for evidence files.
     */
    private function maySeePrivate(int $mediaId): bool
    {
        $actor = $this->app->actor();
        if ($actor === null) {
            return false;
        }
        $policy = $this->app->policy();
        if ($policy->allows($actor, 'media.manage') || $policy->allows($actor, 'program.manage') || $policy->allows($actor, 'application.decide')) {
            return true;
        }
        $statement = $this->app->pdo()->prepare('SELECT a.user_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.media_id = ? LIMIT 1');
        $statement->execute([$mediaId]);
        $ownerId = $statement->fetchColumn();
        if ($ownerId !== false && (int) $ownerId === $actor->id) {
            return true;
        }
        if ($policy->allows($actor, 'application.review')) {
            $assigned = $this->app->pdo()->prepare('SELECT r.id FROM review_assignments r JOIN application_documents d ON d.application_id = r.application_id WHERE d.media_id = ? AND r.reviewer_id = ? LIMIT 1');
            $assigned->execute([$mediaId, $actor->id]);
            return $assigned->fetchColumn() !== false;
        }
        $milestone = $this->app->pdo()->prepare('SELECT user_id, startup_id FROM milestones WHERE evidence_media_id = ? LIMIT 1');
        $milestone->execute([$mediaId]);
        $owner = $milestone->fetch();
        if ($owner !== false) {
            if ($owner['user_id'] !== null && (int) $owner['user_id'] === $actor->id) {
                return true;
            }
            if ($owner['startup_id'] !== null) {
                $member = $this->app->pdo()->prepare('SELECT id FROM startup_members WHERE startup_id = ? AND user_id = ? LIMIT 1');
                $member->execute([(int) $owner['startup_id'], $actor->id]);
                return $member->fetchColumn() !== false;
            }
        }
        // Funding budget documents: the requester, or any staff with a funding role.
        $budget = $this->app->pdo()->prepare('SELECT user_id FROM funding_requests WHERE budget_media_id = ? LIMIT 1');
        $budget->execute([$mediaId]);
        $budgetOwner = $budget->fetchColumn();
        if ($budgetOwner !== false) {
            if ((int) $budgetOwner === $actor->id || $policy->allows($actor, 'funding.manage') || $policy->allows($actor, 'funding.review') || $policy->allows($actor, 'funding.approve')) {
                return true;
            }
        }
        return false;
    }
}

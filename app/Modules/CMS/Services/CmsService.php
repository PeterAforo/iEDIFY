<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class CmsService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(Actor $actor, string $type, string $slug, string $title, array $sections): int
    {
        $this->authorize($actor, 'cms.edit');
        if (!in_array($type, ['page', 'legal', 'team', 'program', 'pillar', 'news', 'story', 'faq', 'event', 'opportunity', 'publication', 'hero', 'auth_copy'], true) || !preg_match('~^/(?:[a-z0-9]+(?:[a-z0-9/-]*[a-z0-9])?)?$~D', $slug) || strlen($slug) > 191) {
            throw new \InvalidArgumentException('Invalid content type or slug.');
        }
        $sections = (new Sections())->validate($sections);
        $this->title($title);
        return (new Transaction($this->pdo))->run(function () use ($actor, $type, $slug, $title, $sections): int {
            $this->execute('INSERT INTO content_items (content_type, slug, title, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$type, $slug, $title]);
            $id = (int) $this->pdo->lastInsertId();
            $this->revision($actor, $id, 1, $title, $sections);
            $this->audit($actor, 'cms.created', $id, 1);
            return $id;
        });
    }

    public function find(Actor $actor, int $id): array
    {
        if (!(new Policy())->allows($actor, 'cms.edit') && !(new Policy())->allows($actor, 'cms.publish')) {
            throw new HttpError(403, 'You do not have permission to view this content.');
        }
        return $this->record($id);
    }

    public function revise(Actor $actor, int $id, int $expectedVersion, string $title, array $sections): void
    {
        $this->authorize($actor, 'cms.edit');
        $this->title($title);
        $sections = (new Sections())->validate($sections);
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $expectedVersion, $title, $sections): void {
            $this->locked($id, $expectedVersion);
            $this->revision($actor, $id, $this->nextRevision($id), $title, $sections);
            $this->execute("UPDATE content_items SET working_state = 'draft', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
            $this->audit($actor, 'cms.revised', $id, $expectedVersion + 1);
        });
    }

    public function submitReview(Actor $actor, int $id, int $expectedVersion): void
    {
        $this->authorize($actor, 'cms.edit');
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $expectedVersion): void {
            $record = $this->locked($id, $expectedVersion);
            if ($record['working_state'] !== 'draft' || $record['status'] === 'archived') {
                throw new HttpError(409, 'Only an active draft can be submitted for review.');
            }
            $this->execute("UPDATE content_items SET working_state = 'review', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
            $this->audit($actor, 'cms.review_requested', $id, $expectedVersion + 1);
        });
    }

    public function publish(Actor $actor, int $id, int $revisionNumber, int $expectedVersion): void
    {
        $this->authorize($actor, 'cms.publish');
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $revisionNumber, $expectedVersion): void {
            $record = $this->locked($id, $expectedVersion);
            if ($record['working_state'] !== 'review' || $record['status'] === 'archived' || $revisionNumber !== $this->nextRevision($id) - 1) {
                throw new HttpError(409, 'Only the current reviewed revision can be published.');
            }
            $revision = $this->getRevision($id, $revisionNumber);
            $flag = $this->pdo->prepare("SELECT COUNT(*) FROM editorial_flags f JOIN source_mappings m ON m.source_record_id = f.source_record_id WHERE m.content_id = ? AND f.status = 'open'");
            $flag->execute([$id]);
            if ((int) $flag->fetchColumn() > 0) {
                throw new HttpError(409, 'Source editorial and policy flags must be resolved before publication.');
            }
            $this->validateMedia(json_decode($revision['sections'], true, 512, JSON_THROW_ON_ERROR));
            $this->execute("UPDATE content_items SET published_revision_id = ?, title = ?, status = 'published', working_state = 'approved', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$revision['id'], $revision['title'], $id]);
            $this->execute('INSERT INTO publication_events (content_id, revision_id, actor_id, action, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))', [$id, $revision['id'], $actor->id, 'published']);
            $this->audit($actor, 'cms.published', $id, $expectedVersion + 1);
        });
    }

    public function restore(Actor $actor, int $id, int $revisionNumber, int $expectedVersion): void
    {
        $this->authorize($actor, 'cms.restore');
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $revisionNumber, $expectedVersion): void {
            $this->locked($id, $expectedVersion);
            $original = $this->getRevision($id, $revisionNumber);
            $sections = (new Sections())->validate(json_decode($original['sections'], true, 512, JSON_THROW_ON_ERROR));
            $this->revision($actor, $id, $this->nextRevision($id), $original['title'], $sections);
            $this->execute("UPDATE content_items SET working_state = 'draft', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
            $this->audit($actor, 'cms.restored_as_draft', $id, $expectedVersion + 1);
        });
    }

    private function validateMedia(array $sections): void
    {
        foreach ($sections as $section) {
            if (isset($section['media_id'])) {
                $statement = $this->pdo->prepare("SELECT id FROM media_assets WHERE id = ? AND classification = 'public_content' AND review_status = 'approved'");
                $statement->execute([$section['media_id']]);
                if ($statement->fetchColumn() === false) {
                    throw new HttpError(409, 'All public media must be reviewed and approved.');
                }
            }
            if (isset($section['items'])) {
                $this->validateMedia($section['items']);
            }
        }
    }

    private function record(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM content_items WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Content was not found.');
        }
        return $record;
    }

    private function locked(int $id, int $version): array
    {
        $record = $this->record($id, true);
        if ((int) $record['version'] !== $version) {
            throw new HttpError(409, 'This content changed. Reload it before saving your changes.');
        }
        return $record;
    }

    private function getRevision(int $id, int $number): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM content_revisions WHERE content_id = ? AND revision_number = ?');
        $statement->execute([$id, $number]);
        $revision = $statement->fetch();
        if ($revision === false) {
            throw new HttpError(404, 'The revision does not belong to this content.');
        }
        return $revision;
    }

    private function nextRevision(int $id): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 FROM content_revisions WHERE content_id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

    private function revision(Actor $actor, int $id, int $number, string $title, array $sections): void
    {
        $this->execute('INSERT INTO content_revisions (content_id, revision_number, title, sections, author_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$id, $number, $title, json_encode($sections, JSON_THROW_ON_ERROR), $actor->id]);
    }

    private function title(string $title): void
    {
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('A title between 1 and 255 characters is required.');
        }
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id, int $version): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'content', (string) $id, ['version' => $version]);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Security\Actor;
use IEdify\Modules\CMS\Services\CmsService;
use PHPUnit\Framework\TestCase;

final class CmsPublishingTest extends TestCase
{
    public function testRevisionPublishingAndRestoringPreserveHistory(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $userId = $this->createUser($pdo);
        $publisher = new Actor($userId, ['cms.edit', 'cms.review', 'cms.publish', 'cms.restore'], true, true, true);
        $editor = new Actor($userId, ['cms.edit'], true, true, true);
        $cms = new CmsService($pdo);
        $id = $cms->create($publisher, 'page', '/test-cms-' . bin2hex(random_bytes(8)), 'First title', [['type' => 'text', 'text' => 'Original']]);
        $cms->submitReview($publisher, $id, 1);
        try {
            $cms->publish($editor, $id, 1, 2);
            self::fail('Editor must not publish.');
        } catch (\IEdify\Core\Http\HttpError $error) {
            self::assertSame(403, $error->status);
        }
        $cms->publish($publisher, $id, 1, 2);
        $published = $cms->find($publisher, $id);
        self::assertSame('published', $published['status']);
        $cms->revise($publisher, $id, 3, 'Second title', [['type' => 'text', 'text' => 'Changed']]);
        self::assertSame($published['published_revision_id'], $cms->find($publisher, $id)['published_revision_id']);
        $cms->restore($publisher, $id, 1, 4);
        $record = $cms->find($publisher, $id);
        self::assertSame(5, (int) $record['version']);
        $statement = $pdo->prepare('SELECT revision_number, title FROM content_revisions WHERE content_id = ? ORDER BY revision_number');
        $statement->execute([$id]);
        self::assertSame(['First title', 'Second title', 'First title'], array_column($statement->fetchAll(), 'title'));
    }

    public function testStaleEditsFailWithoutOverwriting(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit'], true, true, true);
        $cms = new CmsService($pdo);
        $id = $cms->create($actor, 'page', '/test-lock-' . bin2hex(random_bytes(8)), 'A', [['type' => 'text', 'text' => 'A']]);
        $cms->revise($actor, $id, 1, 'B', [['type' => 'text', 'text' => 'B']]);
        $this->expectException(\IEdify\Core\Http\HttpError::class);
        $cms->revise($actor, $id, 1, 'Lost update', [['type' => 'text', 'text' => 'C']]);
    }

    public function testArchiveUnarchiveLifecycle(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit', 'cms.publish'], true, true, true);
        $cms = new CmsService($pdo);
        $id = $cms->create($actor, 'page', '/test-archive-' . bin2hex(random_bytes(8)), 'Archivable', [['type' => 'text', 'text' => 'Body']]);
        $cms->submitReview($actor, $id, 1);
        $cms->publish($actor, $id, 1, 2);
        self::assertSame('published', $cms->find($actor, $id)['status']);

        $cms->archive($actor, $id, 3);
        $record = $cms->find($actor, $id);
        self::assertSame('archived', $record['status']);
        self::assertSame('draft', $record['working_state']);

        try {
            $cms->archive($actor, $id, 4);
            self::fail('Only published content can be archived.');
        } catch (\IEdify\Core\Http\HttpError $error) {
            self::assertSame(409, $error->status);
        }

        $cms->unarchive($actor, $id, 4);
        $record = $cms->find($actor, $id);
        self::assertSame('draft', $record['status']);
        self::assertNull($record['published_revision_id']);
    }

    public function testDeleteRemovesDraftsAndArchivedOnly(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit', 'cms.publish', 'cms.restore'], true, true, true);
        $cms = new CmsService($pdo);
        $id = $cms->create($actor, 'page', '/test-delete-' . bin2hex(random_bytes(8)), 'Deletable', [['type' => 'text', 'text' => 'Body']]);
        $cms->submitReview($actor, $id, 1);
        $cms->publish($actor, $id, 1, 2);

        try {
            $cms->delete($actor, $id, 3);
            self::fail('Published content must not be deleted.');
        } catch (\IEdify\Core\Http\HttpError $error) {
            self::assertSame(409, $error->status);
        }

        $cms->archive($actor, $id, 3);
        $cms->delete($actor, $id, 4);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM content_items WHERE id = ?');
        $statement->execute([$id]);
        self::assertSame(0, (int) $statement->fetchColumn());
        $statement = $pdo->prepare('SELECT COUNT(*) FROM content_revisions WHERE content_id = ?');
        $statement->execute([$id]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    private function createUser(\PDO $pdo): int
    {
        $id = bin2hex(random_bytes(16));
        $statement = $pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$id, $id . '@example.invalid', 'CMS test user', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $pdo->lastInsertId();
    }
}

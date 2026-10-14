<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Security\Actor;
use IEdify\Modules\CMS\Services\TeamService;
use PHPUnit\Framework\TestCase;

final class TeamServiceTest extends TestCase
{
    public function testCreateUpdateAndDeleteMember(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit'], true, true, true);
        $service = new TeamService($pdo);

        $memberId = $service->create($actor, 'Test Person', 'Test role', 'board', false, 5, null);
        $member = $service->member($memberId);
        self::assertSame('Test Person', $member['display_name']);
        self::assertStringStartsWith('/team/test-person', $member['slug']);
        self::assertSame('team', $member['content_type']);
        self::assertSame('draft', $member['status']);

        $revision = $pdo->prepare('SELECT sections FROM content_revisions WHERE content_id = ? ORDER BY revision_number DESC LIMIT 1');
        $revision->execute([(int) $member['content_id']]);
        self::assertNotFalse($revision->fetchColumn(), 'A first bio revision must exist.');

        $service->update($actor, $memberId, 'Test Person Renamed', 'New role', 'advisory', true, 2, null);
        $member = $service->member($memberId);
        self::assertSame('Test Person Renamed', $member['display_name']);
        self::assertSame('advisory', $member['roster_group']);
        self::assertSame(1, (int) $member['youth_adviser']);

        $service->delete($actor, $memberId);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM team_members WHERE id = ' . $memberId)->fetchColumn());
        $item = $pdo->prepare('SELECT status FROM content_items WHERE id = ?');
        $item->execute([(int) $member['content_id']]);
        self::assertSame('archived', $item->fetchColumn(), 'Removing a member archives the bio page.');
    }

    public function testInvalidGroupIsRejected(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit'], true, true, true);
        $this->expectException(\InvalidArgumentException::class);
        (new TeamService($pdo))->create($actor, 'Bad Group', 'Role', 'nowhere', false, 0, null);
    }

    public function testUnapprovedPortraitIsRejected(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $actor = new Actor($this->createUser($pdo), ['cms.edit'], true, true, true);
        $this->expectException(\InvalidArgumentException::class);
        (new TeamService($pdo))->create($actor, 'No Portrait', 'Role', 'board', false, 0, 999999);
    }

    private function createUser(\PDO $pdo): int
    {
        $id = bin2hex(random_bytes(16));
        $statement = $pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$id, $id . '@example.invalid', 'Team test user', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $pdo->lastInsertId();
    }
}

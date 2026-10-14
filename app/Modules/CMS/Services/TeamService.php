<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Team roster administration: the team_members table drives the public
 * /team roster while each member's bio page is a 'team' content item.
 * All mutations are audited; portraits must be approved public media.
 */
final readonly class TeamService
{
    public const GROUPS = ['board' => 'Board', 'programs' => 'Program team', 'advisory' => 'Advisory'];

    public function __construct(private PDO $pdo)
    {
    }

    /** Roster rows joined to their content item, ordered for the admin list. */
    public function roster(): array
    {
        return $this->pdo->query('SELECT t.*, c.slug, c.title, c.status, m.original_filename AS portrait_name FROM team_members t JOIN content_items c ON c.id = t.content_id LEFT JOIN media_assets m ON m.id = t.portrait_asset_id ORDER BY t.roster_group, t.display_order, t.display_name')->fetchAll();
    }

    public function member(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT t.*, c.slug, c.title, c.status, c.version, c.content_type FROM team_members t JOIN content_items c ON c.id = t.content_id WHERE t.id = ?');
        $statement->execute([$id]);
        $member = $statement->fetch();
        if ($member === false) {
            throw new HttpError(404, 'Team member was not found.');
        }
        return $member;
    }

    /**
     * Create a member: one content item (bio page under /team/{slug}) and
     * its roster row in a single transaction.
     */
    public function create(Actor $actor, string $name, string $role, string $group, bool $youthAdviser, int $order, ?int $portraitId): int
    {
        $this->authorize($actor);
        [$name, $role, $group, $order] = $this->validate($name, $role, $group, $order);
        $this->portrait($portraitId);
        $slug = $this->uniqueSlug($name);
        return (new Transaction($this->pdo))->run(function () use ($actor, $name, $role, $group, $youthAdviser, $order, $portraitId, $slug): int {
            $this->pdo->prepare("INSERT INTO content_items (content_type, slug, title, created_at, updated_at) VALUES ('team', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$slug, $name]);
            $contentId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO content_revisions (content_id, revision_number, title, sections, author_id, created_at) VALUES (?, 1, ?, ?, ?, UTC_TIMESTAMP(6))')
                ->execute([$contentId, $name, json_encode([['type' => 'text', 'text' => 'Tell the story of ' . $name . ' here.']], JSON_THROW_ON_ERROR), $actor->id]);
            $this->pdo->prepare('INSERT INTO team_members (content_id, display_name, role_label, roster_group, youth_adviser, display_order, portrait_asset_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$contentId, $name, $role, $group, (int) $youthAdviser, $order, $portraitId]);
            $memberId = (int) $this->pdo->lastInsertId();
            (new AuditLog($this->pdo))->record($actor->id, 'team.created', 'team_members', (string) $memberId);
            return $memberId;
        });
    }

    /** Update roster fields only (bio content is revised through CmsService). */
    public function update(Actor $actor, int $id, string $name, string $role, string $group, bool $youthAdviser, int $order, ?int $portraitId): void
    {
        $this->authorize($actor);
        $member = $this->member($id);
        [$name, $role, $group, $order] = $this->validate($name, $role, $group, $order);
        $this->portrait($portraitId);
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $member, $name, $role, $group, $youthAdviser, $order, $portraitId): void {
            $this->pdo->prepare('UPDATE team_members SET display_name = ?, role_label = ?, roster_group = ?, youth_adviser = ?, display_order = ?, portrait_asset_id = ? WHERE id = ?')
                ->execute([$name, $role, $group, (int) $youthAdviser, $order, $portraitId, $id]);
            $this->pdo->prepare('UPDATE content_items SET title = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$name, $member['content_id']]);
            (new AuditLog($this->pdo))->record($actor->id, 'team.updated', 'team_members', (string) $id);
        });
    }

    /** Remove a roster row and archive its bio page (revisions preserved). */
    public function delete(Actor $actor, int $id): void
    {
        $this->authorize($actor);
        $member = $this->member($id);
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $member): void {
            $this->pdo->prepare('DELETE FROM team_members WHERE id = ?')->execute([$id]);
            $this->pdo->prepare("UPDATE content_items SET status = 'archived', published_revision_id = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$member['content_id']]);
            (new AuditLog($this->pdo))->record($actor->id, 'team.deleted', 'team_members', (string) $id);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = $base !== '' ? $base : 'member';
        $slug = '/team/' . $base;
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM content_items WHERE slug = ?');
        for ($i = 2; ; $i++) {
            $check->execute([$slug]);
            if ((int) $check->fetchColumn() === 0) {
                return $slug;
            }
            $slug = '/team/' . $base . '-' . $i;
        }
    }

    private function validate(string $name, string $role, string $group, int $order): array
    {
        $name = trim($name);
        $role = trim($role);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('A display name is required.');
        }
        if ($role === '' || mb_strlen($role) > 255) {
            throw new \InvalidArgumentException('A role is required.');
        }
        if (!array_key_exists($group, self::GROUPS)) {
            throw new \InvalidArgumentException('Choose a valid team group.');
        }
        return [$name, $role, $group, max(0, min(999, $order))];
    }

    private function portrait(?int $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }
        $statement = $this->pdo->prepare("SELECT id FROM media_assets WHERE id = ? AND classification = 'public_content' AND review_status = 'approved' AND mime LIKE 'image/%'");
        $statement->execute([$mediaId]);
        if ($statement->fetchColumn() === false) {
            throw new \InvalidArgumentException('Portraits must be approved public images.');
        }
    }

    private function authorize(Actor $actor): void
    {
        if (!(new Policy())->allows($actor, 'cms.edit')) {
            throw new HttpError(403, 'You do not have permission to manage the team.');
        }
    }
}

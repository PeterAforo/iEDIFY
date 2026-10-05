<?php

declare(strict_types=1);

namespace IEdify\Modules\Community\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

final readonly class CommunityService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function upsertProfile(Actor $actor, string $displayName, ?string $bio, ?string $sector, ?string $location, bool $discoverable): void
    {
        $this->authorizeMember($actor);
        if (trim($displayName) === '' || mb_strlen($displayName) > 120) {
            throw new \InvalidArgumentException('A display name of 1-120 characters is required.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $displayName, $bio, $sector, $location, $discoverable): void {
            $this->execute('INSERT INTO member_profiles (user_id, display_name, bio, sector, location, discoverable, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), bio = VALUES(bio), sector = VALUES(sector), location = VALUES(location), discoverable = VALUES(discoverable), updated_at = UTC_TIMESTAMP(6)', [$actor->id, $displayName, $bio !== null ? mb_substr($bio, 0, 4000) : null, $sector, $location, $discoverable ? 1 : 0]);
            $this->audit($actor, 'community.profile_saved', $actor->id);
        });
    }

    public function createGroup(Actor $actor, string $slug, string $name, ?string $description, ?string $sector, string $visibility): int
    {
        $this->authorizeMember($actor);
        if (!in_array($visibility, ['public', 'private'], true) || !preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || trim($name) === '') {
            throw new \InvalidArgumentException('Groups need a slug, name and public/private visibility.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $slug, $name, $description, $sector, $visibility): int {
            $this->execute('INSERT INTO community_groups (slug, name, description, sector, visibility, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$slug, $name, $description, $sector, $visibility, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->execute("INSERT INTO group_members (group_id, user_id, role, status, created_at) VALUES (?, ?, 'moderator', 'active', UTC_TIMESTAMP(6))", [$id, $actor->id]);
            $this->audit($actor, 'community.group_created', $id);
            return $id;
        });
    }

    /** Public groups join immediately; private groups create a pending request. */
    public function joinGroup(Actor $actor, int $groupId): string
    {
        $this->authorizeMember($actor);
        return (new Transaction($this->pdo))->run(function () use ($actor, $groupId): string {
            $group = $this->group($groupId, true);
            $status = $group['visibility'] === 'public' ? 'active' : 'pending';
            $this->execute("INSERT INTO group_members (group_id, user_id, role, status, created_at) VALUES (?, ?, 'member', ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = VALUES(status)", [$groupId, $actor->id, $status]);
            $this->audit($actor, 'community.group_' . ($status === 'active' ? 'joined' : 'requested'), $groupId);
            return $status;
        });
    }

    /** Group moderators or community staff approve pending members. */
    public function approveMember(Actor $actor, int $groupId, int $userId, bool $approve): void
    {
        (new Transaction($this->pdo))->run(function () use ($actor, $groupId, $userId, $approve): void {
            $this->authorizeGroupModerator($actor, $groupId);
            $status = $approve ? 'active' : 'rejected';
            $statement = $this->pdo->prepare("UPDATE group_members SET status = ? WHERE group_id = ? AND user_id = ? AND status = 'pending'");
            $statement->execute([$status, $groupId, $userId]);
            if ($statement->rowCount() === 0) {
                throw new HttpError(409, 'There is no pending membership to decide.');
            }
            $this->audit($actor, 'community.member_' . ($approve ? 'approved' : 'rejected'), $groupId);
        });
    }

    /** Posting requires active membership; public-group posts still need membership. */
    public function createPost(Actor $actor, int $groupId, string $title, string $body): int
    {
        $this->authorizeMember($actor);
        if (trim($title) === '' || trim($body) === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Posts need a title and body.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $groupId, $title, $body): int {
            $this->requireMembership($actor, $groupId);
            $this->execute("INSERT INTO community_posts (group_id, user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$groupId, $actor->id, mb_substr($title, 0, 255), mb_substr($body, 0, 50000)]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'community.post_created', $id);
            return $id;
        });
    }

    public function createComment(Actor $actor, int $postId, string $body): int
    {
        $this->authorizeMember($actor);
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Comments need content.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $postId, $body): int {
            $post = $this->pdo->prepare("SELECT group_id, status FROM community_posts WHERE id = ?");
            $post->execute([$postId]);
            $record = $post->fetch();
            if ($record === false || $record['status'] !== 'visible') {
                throw new HttpError(404, 'Post was not found.');
            }
            $this->requireMembership($actor, (int) $record['group_id']);
            $this->execute('INSERT INTO community_comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$postId, $actor->id, mb_substr($body, 0, 4000)]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'community.comment_created', $id);
            return $id;
        });
    }

    public function bookmark(Actor $actor, int $postId, bool $add): void
    {
        $this->authorizeMember($actor);
        (new Transaction($this->pdo))->run(function () use ($actor, $postId, $add): void {
            if ($add) {
                $this->execute('INSERT IGNORE INTO bookmarks (user_id, post_id, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))', [$actor->id, $postId]);
            } else {
                $this->execute('DELETE FROM bookmarks WHERE user_id = ? AND post_id = ?', [$actor->id, $postId]);
            }
        });
    }

    /** Report a post or comment for moderation. */
    public function report(Actor $actor, string $targetType, int $targetId, string $reason): void
    {
        $this->authorizeMember($actor);
        if (!in_array($targetType, ['post', 'comment'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Reports need a post/comment target and a reason.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $targetType, $targetId, $reason): void {
            $table = $targetType === 'post' ? 'community_posts' : 'community_comments';
            $exists = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND status != 'removed'");
            $exists->execute([$targetId]);
            if ($exists->fetchColumn() === false) {
                throw new HttpError(404, 'The reported content was not found.');
            }
            $this->execute("INSERT INTO moderation_reports (target_type, target_id, reporter_id, reason, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))", [$targetType, $targetId, $actor->id, $reason]);
            $this->audit($actor, 'community.reported', $targetId);
        });
    }

    /** Moderator action: hide/remove content, dismiss reports. */
    public function moderate(Actor $actor, int $reportId, string $action, ?string $note): void
    {
        $this->authorize($actor, 'community.moderate');
        if (!in_array($action, ['hide', 'remove', 'dismiss'], true)) {
            throw new \InvalidArgumentException('Actions are hide, remove or dismiss.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $reportId, $action, $note): void {
            $report = $this->pdo->prepare("SELECT * FROM moderation_reports WHERE id = ? AND status = 'open' FOR UPDATE");
            $report->execute([$reportId]);
            $record = $report->fetch();
            if ($record === false) {
                throw new HttpError(404, 'Report was not found or already resolved.');
            }
            if ($action !== 'dismiss') {
                $table = $record['target_type'] === 'post' ? 'community_posts' : 'community_comments';
                $status = $action === 'hide' ? 'hidden' : 'removed';
                $this->execute("UPDATE {$table} SET status = ? WHERE id = ?", [$status, (int) $record['target_id']]);
            }
            $this->execute("UPDATE moderation_reports SET status = ?, resolved_by = ?, resolution_note = ?, resolved_at = UTC_TIMESTAMP(6) WHERE id = ?", [$action === 'dismiss' ? 'dismissed' : 'actioned', $actor->id, $note !== null ? mb_substr($note, 0, 1000) : null, $reportId]);
            $this->audit($actor, 'community.moderation_' . $action, $reportId);
        });
    }

    /** Groups visible to the actor: public groups + private groups they belong to. */
    public function groupsFor(Actor $actor): array
    {
        $statement = $this->pdo->prepare("SELECT g.*, m.status AS membership, m.role AS member_role, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.status = 'active') AS member_count FROM community_groups g LEFT JOIN group_members m ON m.group_id = g.id AND m.user_id = ? AND m.status != 'left' WHERE g.visibility = 'public' OR m.user_id IS NOT NULL ORDER BY g.name");
        $statement->execute([$actor->id]);
        return $statement->fetchAll();
    }

    /** Posts in a group — membership required; hidden content is staff/author only. */
    public function postsIn(Actor $actor, int $groupId): array
    {
        $this->authorizeMember($actor);
        $group = $this->group($groupId);
        $member = $this->membership($actor, $groupId);
        if ($group['visibility'] === 'private' && ($member === null || $member['status'] !== 'active') && !(new Policy())->allows($actor, 'community.moderate')) {
            throw new HttpError(404, 'This group is not available.');
        }
        $statement = $this->pdo->prepare("SELECT p.*, mp.display_name AS author FROM community_posts p LEFT JOIN member_profiles mp ON mp.user_id = p.user_id WHERE p.group_id = ? AND p.status = 'visible' ORDER BY p.id DESC LIMIT 200");
        $statement->execute([$groupId]);
        return $statement->fetchAll();
    }

    /** Post detail + visible comments; enforces the same group privacy. */
    public function post(Actor $actor, int $postId): array
    {
        $post = $this->pdo->prepare('SELECT p.*, mp.display_name AS author FROM community_posts p LEFT JOIN member_profiles mp ON mp.user_id = p.user_id WHERE p.id = ? AND p.status = \'visible\'');
        $post->execute([$postId]);
        $record = $post->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Post was not found.');
        }
        $group = $this->group((int) $record['group_id']);
        $member = $this->membership($actor, (int) $record['group_id']);
        if ($group['visibility'] === 'private' && ($member === null || $member['status'] !== 'active') && !(new Policy())->allows($actor, 'community.moderate')) {
            throw new HttpError(404, 'Post was not found.');
        }
        $comments = $this->pdo->prepare("SELECT c.*, mp.display_name AS author FROM community_comments c LEFT JOIN member_profiles mp ON mp.user_id = c.user_id WHERE c.post_id = ? AND c.status = 'visible' ORDER BY c.id");
        $comments->execute([$postId]);
        $record['comments'] = $comments->fetchAll();
        $record['bookmarked'] = $this->bookmarked($actor->id, $postId);
        return $record;
    }

    /** Public board of currently open opportunities. */
    public function openOpportunities(): array
    {
        return $this->pdo->query("SELECT * FROM opportunities WHERE status = 'open' AND (deadline IS NULL OR deadline >= UTC_DATE()) ORDER BY deadline IS NULL, deadline, id DESC LIMIT 200")->fetchAll();
    }

    public function opportunity(int $id): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM opportunities WHERE id = ? AND status = 'open'");
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new HttpError(404, 'Opportunity was not found.');
        }
        return $row;
    }

    public function createOpportunity(Actor $actor, array $data): int
    {
        $this->authorize($actor, 'program.manage');
        $title = trim((string) ($data['title'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255 || $summary === '') {
            throw new \InvalidArgumentException('A title and summary are required.');
        }
        $deadline = trim((string) ($data['deadline'] ?? ''));
        if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            throw new \InvalidArgumentException('Deadline must be a date.');
        }
        $category = trim((string) ($data['category'] ?? 'general'));
        if ($category === '' || mb_strlen($category) > 60) {
            throw new \InvalidArgumentException('Category is too long.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $title, $category, $summary, $data, $deadline): int {
            $this->pdo->prepare('INSERT INTO opportunities (title, category, summary, details, deadline, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')
                ->execute([$title, $category, $summary, trim((string) ($data['details'] ?? '')) !== '' ? trim((string) $data['details']) : null, $deadline !== '' ? $deadline : null, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'opportunity.created', $id);
            return $id;
        });
    }

    public function openReports(): array
    {
        return $this->pdo->query("SELECT r.*, u.email AS reporter FROM moderation_reports r JOIN users u ON u.id = r.reporter_id WHERE r.status = 'open' ORDER BY r.id LIMIT 200")->fetchAll();
    }

    /** Opt-in member directory — only discoverable profiles, no emails. */
    public function discoverableMembers(string $query = ''): array
    {
        $sql = "SELECT mp.display_name, mp.sector, mp.location, mp.bio FROM member_profiles mp WHERE mp.discoverable = TRUE";
        $params = [];
        if ($query !== '' && mb_strlen($query) <= 80) {
            $sql .= ' AND (mp.display_name LIKE ? OR mp.sector LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $params = [$like, $like];
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY mp.display_name LIMIT 100');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function bookmarked(int $userId, int $postId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM bookmarks WHERE user_id = ? AND post_id = ?');
        $statement->execute([$userId, $postId]);
        return $statement->fetchColumn() !== false;
    }

    private function group(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM community_groups WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $group = $statement->fetch();
        if ($group === false) {
            throw new HttpError(404, 'Group was not found.');
        }
        return $group;
    }

    private function membership(Actor $actor, int $groupId): ?array
    {
        $statement = $this->pdo->prepare("SELECT status, role FROM group_members WHERE group_id = ? AND user_id = ?");
        $statement->execute([$groupId, $actor->id]);
        $member = $statement->fetch();
        return $member === false ? null : $member;
    }

    private function requireMembership(Actor $actor, int $groupId): void
    {
        $member = $this->membership($actor, $groupId);
        if ($member === null || $member['status'] !== 'active') {
            throw new HttpError(403, 'Join this group before posting.');
        }
    }

    private function authorizeGroupModerator(Actor $actor, int $groupId): void
    {
        if ((new Policy())->allows($actor, 'community.moderate')) {
            return;
        }
        $member = $this->membership($actor, $groupId);
        if ($member === null || $member['status'] !== 'active' || $member['role'] !== 'moderator') {
            throw new HttpError(403, 'Only group moderators can approve members.');
        }
    }

    private function authorizeMember(Actor $actor): void
    {
        $policy = new Policy();
        if (!$policy->allows($actor, 'community.member') && !$policy->allows($actor, 'community.moderate')) {
            throw new HttpError(403, 'Community access requires a member account.');
        }
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'community', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Modules\Partners\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Partner/donor organizations, memberships, proposals, commitments and
 * received funds, plus the portal sharing model. Portal access resolves
 * through an ACTIVE membership to an explicit, unrevoked share — guessed
 * URLs and direct download endpoints both enforce the same check, and
 * revoking a share immediately revokes download access. Commitments are
 * pledges; partner_received holds money actually received. Staff notes
 * never appear in portal output.
 */
final readonly class PartnerService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createOrg(Actor $actor, string $name, string $type, ?string $website): int
    {
        $this->authorize($actor, 'partner.manage');
        if (trim($name) === '' || !in_array($type, ['donor', 'partner', 'sponsor', 'other'], true)) {
            throw new \InvalidArgumentException('An organization needs a name and a valid type.');
        }
        if ($website !== null && !preg_match('~^https?://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(/|$)~iD', $website)) {
            throw new \InvalidArgumentException('Websites must be http(s) URLs.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $name, $type, $website): int {
            $this->execute('INSERT INTO partner_organizations (name, org_type, website, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [mb_substr($name, 0, 200), $type, $website, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'partner.org_created', $id);
            return $id;
        });
    }

    /** Staff or a partner org admin may add a member. */
    public function addMember(Actor $actor, int $orgId, int $userId, string $role): void
    {
        $this->authorizeOrgAdmin($actor, $orgId);
        if (!in_array($role, ['admin', 'viewer'], true)) {
            throw new \InvalidArgumentException('Member roles are admin or viewer.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $userId, $role): void {
            $this->record('partner_organizations', $orgId);
            $this->record('users', $userId);
            $this->execute("INSERT INTO partner_memberships (org_id, user_id, member_role, status, invited_by, created_at, activated_at) VALUES (?, ?, ?, 'active', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE member_role = VALUES(member_role), status = 'active', activated_at = UTC_TIMESTAMP(6)", [$orgId, $userId, $role, $actor->id]);
            $this->audit($actor, 'partner.member_added', $orgId);
        });
    }

    public function suspendMember(Actor $actor, int $orgId, int $userId): void
    {
        $this->authorizeOrgAdmin($actor, $orgId);
        if ($actor->id === $userId) {
            throw new HttpError(403, 'You cannot suspend your own membership.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $userId): void {
            $this->execute("UPDATE partner_memberships SET status = 'suspended' WHERE org_id = ? AND user_id = ?", [$orgId, $userId]);
            $this->audit($actor, 'partner.member_suspended', $orgId);
        });
    }

    public function createProposal(Actor $actor, int $orgId, string $title, string $summary): int
    {
        $this->authorizeOrgMember($actor, $orgId);
        if (trim($title) === '' || trim($summary) === '') {
            throw new \InvalidArgumentException('A proposal needs a title and summary.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $title, $summary): int {
            $this->execute("INSERT INTO partner_proposals (org_id, title, summary, status, created_by, created_at, updated_at) VALUES (?, ?, ?, 'draft', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$orgId, mb_substr($title, 0, 255), $summary, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'partner.proposal_created', $id);
            return $id;
        });
    }

    public function transitionProposal(Actor $actor, int $proposalId, string $status, int $expectedVersion): void
    {
        $proposal = $this->proposalRecord($proposalId);
        $this->authorizeOrgMember($actor, (int) $proposal['org_id']);
        if (!in_array($status, ['draft', 'submitted', 'under_review', 'committed', 'declined', 'withdrawn'], true)) {
            throw new \InvalidArgumentException('Unknown proposal status.');
        }
        // Partner-side actors can only submit or withdraw; pipeline states are staff-only.
        if (!(new Policy())->allows($actor, 'partner.manage') && !in_array($status, ['submitted', 'withdrawn'], true)) {
            throw new HttpError(403, 'Only staff can move a proposal through the pipeline.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $proposalId, $status, $expectedVersion): void {
            $proposal = $this->proposalRecord($proposalId, true);
            if ((int) $proposal['version'] !== $expectedVersion) {
                throw new HttpError(409, 'This record changed. Reload it before saving.');
            }
            $this->execute('UPDATE partner_proposals SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $proposalId]);
            $this->audit($actor, 'partner.proposal_' . $status, $proposalId);
        });
    }

    /** Staff record a pledge. Commitments are NOT received funds. */
    public function recordCommitment(Actor $actor, int $orgId, ?int $proposalId, string $amount, string $currency, string $committedOn, ?string $conditions): int
    {
        $this->authorize($actor, 'partner.manage');
        if (!is_numeric($amount) || (float) $amount <= 0 || !preg_match('~^[A-Z]{3}$~D', $currency) || strtotime($committedOn) === false) {
            throw new \InvalidArgumentException('A commitment needs a positive amount, ISO currency and a date.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $proposalId, $amount, $currency, $committedOn, $conditions): int {
            $this->record('partner_organizations', $orgId);
            if ($proposalId !== null) {
                $proposal = $this->proposalRecord($proposalId);
                if ((int) $proposal['org_id'] !== $orgId) {
                    throw new \InvalidArgumentException('That proposal belongs to a different organization.');
                }
            }
            $this->execute("INSERT INTO partner_commitments (org_id, proposal_id, amount, currency, committed_on, conditions, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))", [$orgId, $proposalId, $amount, $currency, gmdate('Y-m-d', strtotime($committedOn)), $conditions, $actor->id]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'partner.commitment_recorded', $id);
            return $id;
        });
    }

    /** Staff record actual funds received; total can never exceed the commitment. */
    public function recordReceived(Actor $actor, int $commitmentId, string $reference, string $amount, string $currency, string $receivedOn, ?int $evidenceMediaId): int
    {
        $this->authorize($actor, 'partner.manage');
        if (trim($reference) === '' || !is_numeric($amount) || (float) $amount <= 0 || strtotime($receivedOn) === false) {
            throw new \InvalidArgumentException('Received funds need a reference, amount and date.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $commitmentId, $reference, $amount, $currency, $receivedOn, $evidenceMediaId): int {
            $commitment = $this->commitmentRecord($commitmentId, true);
            if ($commitment['status'] === 'cancelled') {
                throw new HttpError(409, 'Cancelled commitments cannot receive funds.');
            }
            if ($currency !== $commitment['currency']) {
                throw new HttpError(422, 'Received funds must use the commitment currency.');
            }
            $received = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM partner_received WHERE commitment_id = ?');
            $received->execute([$commitmentId]);
            if (bccomp(bcadd((string) $received->fetchColumn(), $amount, 2), (string) $commitment['amount'], 2) === 1) {
                throw new HttpError(422, 'Received funds would exceed the commitment.');
            }
            try {
                $this->execute('INSERT INTO partner_received (commitment_id, reference, amount, currency, received_on, evidence_media_id, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$commitmentId, mb_substr($reference, 0, 120), $amount, $currency, gmdate('Y-m-d', strtotime($receivedOn)), $evidenceMediaId, $actor->id]);
            } catch (\PDOException $error) {
                if (str_contains($error->getMessage(), 'Duplicate')) {
                    throw new HttpError(409, 'This receipt reference already exists.');
                }
                throw $error;
            }
            $id = (int) $this->pdo->lastInsertId();
            $received->execute([$commitmentId]);
            $total = (string) $received->fetchColumn();
            $status = bccomp($total, (string) $commitment['amount'], 2) === 0 ? 'fulfilled' : 'partial';
            $this->execute('UPDATE partner_commitments SET status = ? WHERE id = ?', [$status, $commitmentId]);
            $this->audit($actor, 'partner.received_recorded', $id);
            return $id;
        });
    }

    /** Staff share a published resource to one org; re-sharing restores a revoked share. */
    public function share(Actor $actor, int $orgId, string $resourceType, int $resourceId): void
    {
        $this->authorize($actor, 'partner.manage');
        if (!in_array($resourceType, ['report', 'document', 'project'], true)) {
            throw new \InvalidArgumentException('Only reports, documents and projects can be shared.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $resourceType, $resourceId): void {
            $this->record('partner_organizations', $orgId);
            if ($resourceType === 'report') {
                $report = $this->reportRecord($resourceId);
                if ($report['status'] !== 'published') {
                    throw new HttpError(409, 'Only published reports can be shared.');
                }
            } elseif ($resourceType === 'document') {
                $this->record('media_assets', $resourceId);
            } else {
                $this->record('programs', $resourceId);
            }
            $this->execute('INSERT INTO partner_shares (org_id, resource_type, resource_id, shared_by, shared_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE shared_by = VALUES(shared_by), shared_at = UTC_TIMESTAMP(6), revoked_at = NULL', [$orgId, $resourceType, $resourceId, $actor->id]);
            $this->audit($actor, 'partner.resource_shared', $resourceId);
        });
    }

    /** Revoking a share immediately blocks portal views and downloads. */
    public function revokeShare(Actor $actor, int $orgId, string $resourceType, int $resourceId): void
    {
        $this->authorize($actor, 'partner.manage');
        (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $resourceType, $resourceId): void {
            $this->execute('UPDATE partner_shares SET revoked_at = UTC_TIMESTAMP(6) WHERE org_id = ? AND resource_type = ? AND resource_id = ? AND revoked_at IS NULL', [$orgId, $resourceType, $resourceId]);
            $this->audit($actor, 'partner.share_revoked', $resourceId);
        });
    }

    public function addNote(Actor $actor, int $orgId, string $note): void
    {
        $this->authorize($actor, 'partner.manage');
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A note cannot be empty.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $orgId, $note): void {
            $this->execute('INSERT INTO partner_notes (org_id, note, created_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', [$orgId, $note, $actor->id]);
            $this->audit($actor, 'partner.note_added', $orgId);
        });
    }

    /** The single authorization point every portal route calls. */
    public function canAccess(Actor $actor, string $resourceType, int $resourceId): ?int
    {
        if (!(new Policy())->allows($actor, 'partner.shared.read')) {
            return null;
        }
        $statement = $this->pdo->prepare("SELECT s.org_id FROM partner_shares s JOIN partner_memberships m ON m.org_id = s.org_id AND m.user_id = ? AND m.status = 'active' WHERE s.resource_type = ? AND s.resource_id = ? AND s.revoked_at IS NULL LIMIT 1");
        $statement->execute([$actor->id, $resourceType, $resourceId]);
        $orgId = $statement->fetchColumn();
        return $orgId !== false ? (int) $orgId : null;
    }

    public function logAccess(int $orgId, Actor $actor, string $action, string $resourceType, int $resourceId): void
    {
        $this->execute('INSERT INTO partner_access_log (org_id, user_id, action, resource_type, resource_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$orgId, $actor->id, $action, $resourceType, $resourceId]);
    }

    /** Resources shared to orgs the actor actively belongs to — never other orgs'. */
    public function sharedWith(Actor $actor): array
    {
        if (!(new Policy())->allows($actor, 'partner.shared.read')) {
            return [];
        }
        $statement = $this->pdo->prepare("SELECT s.resource_type, s.resource_id, s.shared_at, o.name AS org_name FROM partner_shares s JOIN partner_memberships m ON m.org_id = s.org_id AND m.user_id = ? AND m.status = 'active' JOIN partner_organizations o ON o.id = s.org_id WHERE s.revoked_at IS NULL ORDER BY s.shared_at DESC");
        $statement->execute([$actor->id]);
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $item['title'] = match ($item['resource_type']) {
                'report' => $this->pdo->query("SELECT title FROM impact_reports WHERE id = " . (int) $item['resource_id'] . " AND status = 'published'")->fetchColumn(),
                'document' => $this->pdo->query('SELECT original_filename FROM media_assets WHERE id = ' . (int) $item['resource_id'])->fetchColumn(),
                default => $this->pdo->query('SELECT title FROM programs WHERE id = ' . (int) $item['resource_id'])->fetchColumn(),
            } ?: '(unavailable)';
        }
        return $items;
    }

    public function membershipsOf(Actor $actor): array
    {
        $statement = $this->pdo->prepare("SELECT m.org_id, m.member_role, o.name, o.org_type FROM partner_memberships m JOIN partner_organizations o ON o.id = m.org_id WHERE m.user_id = ? AND m.status = 'active'");
        $statement->execute([$actor->id]);
        return $statement->fetchAll();
    }

    public function orgs(): array
    {
        return $this->pdo->query("SELECT o.*, (SELECT COUNT(*) FROM partner_memberships m WHERE m.org_id = o.id AND m.status = 'active') AS members, (SELECT COUNT(*) FROM partner_shares s WHERE s.org_id = o.id AND s.revoked_at IS NULL) AS shares FROM partner_organizations o ORDER BY o.name")->fetchAll();
    }

    public function orgDetail(int $orgId): array
    {
        $org = $this->record('partner_organizations', $orgId);
        $queries = [
            'members' => "SELECT m.*, u.name, u.email FROM partner_memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = {$orgId} ORDER BY u.name",
            'proposals' => "SELECT * FROM partner_proposals WHERE org_id = {$orgId} ORDER BY id DESC",
            'commitments' => "SELECT c.*, (SELECT COALESCE(SUM(r.amount),0) FROM partner_received r WHERE r.commitment_id = c.id) AS received_total FROM partner_commitments c WHERE c.org_id = {$orgId} ORDER BY committed_on DESC",
            'received' => "SELECT r.*, c.amount AS commitment_amount FROM partner_received r JOIN partner_commitments c ON c.id = r.commitment_id WHERE c.org_id = {$orgId} ORDER BY received_on DESC",
            'shares' => "SELECT * FROM partner_shares WHERE org_id = {$orgId} ORDER BY shared_at DESC",
            'notes' => "SELECT n.*, u.name AS author FROM partner_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.org_id = {$orgId} ORDER BY n.id DESC",
        ];
        foreach ($queries as $key => $sql) {
            $org[$key] = $this->pdo->query($sql)->fetchAll();
        }
        return $org;
    }

    /** Portal-visible org data: shared items, proposals and commitments only — no staff notes. */
    public function portalData(Actor $actor): array
    {
        $memberships = $this->membershipsOf($actor);
        $shared = $this->sharedWith($actor);
        $byOrg = [];
        foreach ($memberships as $membership) {
            $byOrg[$membership['org_id']] = ['org' => $membership, 'shared' => [], 'proposals' => [], 'commitments' => []];
        }
        foreach ($shared as $item) {
            $orgId = $this->pdo->query("SELECT org_id FROM partner_shares WHERE resource_type = " . $this->pdo->quote($item['resource_type']) . ' AND resource_id = ' . (int) $item['resource_id'] . ' AND revoked_at IS NULL')->fetchColumn();
            if ($orgId !== false && isset($byOrg[(int) $orgId])) {
                $byOrg[(int) $orgId]['shared'][] = $item;
            }
        }
        foreach ($byOrg as $orgId => &$data) {
            $proposals = $this->pdo->prepare('SELECT * FROM partner_proposals WHERE org_id = ? ORDER BY id DESC');
            $proposals->execute([$orgId]);
            $data['proposals'] = $proposals->fetchAll();
            $commitments = $this->pdo->prepare('SELECT c.*, (SELECT COALESCE(SUM(r.amount),0) FROM partner_received r WHERE r.commitment_id = c.id) AS received_total FROM partner_commitments c WHERE c.org_id = ? ORDER BY committed_on DESC');
            $commitments->execute([$orgId]);
            $data['commitments'] = $commitments->fetchAll();
        }
        return $byOrg;
    }

    private function authorizeOrgMember(Actor $actor, int $orgId): void
    {
        if ((new Policy())->allows($actor, 'partner.manage')) {
            return;
        }
        $statement = $this->pdo->prepare("SELECT id FROM partner_memberships WHERE org_id = ? AND user_id = ? AND status = 'active'");
        $statement->execute([$orgId, $actor->id]);
        if ($statement->fetchColumn() === false) {
            throw new HttpError(403, 'You are not an active member of this organization.');
        }
    }

    private function authorizeOrgAdmin(Actor $actor, int $orgId): void
    {
        if ((new Policy())->allows($actor, 'partner.manage')) {
            return;
        }
        $statement = $this->pdo->prepare("SELECT id FROM partner_memberships WHERE org_id = ? AND user_id = ? AND member_role = 'admin' AND status = 'active'");
        $statement->execute([$orgId, $actor->id]);
        if ($statement->fetchColumn() === false) {
            throw new HttpError(403, 'Only organization administrators can manage members.');
        }
    }

    private function proposalRecord(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM partner_proposals WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Proposal was not found.');
        }
        return $record;
    }

    private function commitmentRecord(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM partner_commitments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Commitment was not found.');
        }
        return $record;
    }

    private function reportRecord(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM impact_reports WHERE id = ?');
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Report was not found.');
        }
        return $record;
    }

    private function record(string $table, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $statement->execute([$id]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Record was not found.');
        }
        return $record;
    }

    private function authorize(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'partner', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

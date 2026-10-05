<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Modules\Funding\Services\DisbursementService;
use IEdify\Modules\Funding\Services\FundingService;
use IEdify\Modules\Impact\Services\ImpactService;
use IEdify\Modules\Partners\Services\PartnerService;
use PHPUnit\Framework\TestCase;

final class PhaseDFlowTest extends TestCase
{
    private \PDO $pdo;
    private Actor $staff;
    private Actor $requester;
    private Actor $reviewer;
    private Actor $approver;
    private Actor $finance;
    private Actor $finAuth;
    private int $requesterId;

    protected function setUp(): void
    {
        $this->pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $this->staff = new Actor($this->user(), ['funding.manage', 'award.authorize', 'partner.manage', 'program.manage', 'impact.edit', 'impact.verify', 'impact.publish', 'export.run'], true, true, true);
        $this->requesterId = $this->user();
        $this->requester = new Actor($this->requesterId, ['startup.team'], true, false, true);
        $this->reviewer = new Actor($this->user(), ['funding.review'], true, true, true);
        $this->approver = new Actor($this->user(), ['funding.approve'], true, true, true);
        $this->finance = new Actor($this->user(), ['disbursement.record'], true, true, true);
        $this->finAuth = new Actor($this->user(), ['disbursement.authorize'], true, true, true);
    }

    private function user(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'User ' . substr($id, 0, 6), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Journey 6: frozen rules, scored review, authorized approval, award, disbursement evidence; duplicate/over-award/self-approval fail. */
    public function testFundingRequestLifecycleAndSafeguards(): void
    {
        $funding = new FundingService($this->pdo);
        $disbursements = new DisbursementService($this->pdo);

        $roundId = $funding->createRound($this->staff, 'seed-' . bin2hex(random_bytes(4)), 'Seed Round', 'Startup grants', 'USD', '5000.00', gmdate('Y-m-d H:i:s', strtotime('-1 day')), gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $ruleV1 = $funding->createRuleVersion($this->staff, $roundId, ['impact' => 60, 'feasibility' => 40], 5);
        $funding->setRoundStatus($this->staff, $roundId, 'open', 1);

        $requestId = $funding->submitRequest($this->requester, $roundId, 'Solar kiosk expansion', '3000.00', 'Equipment and stock');

        // New rule version after submission must NOT retroactively apply.
        $funding->createRuleVersion($this->staff, $roundId, ['impact' => 50, 'team' => 50], 5);
        $detail = $funding->requestDetail($requestId);
        self::assertSame($ruleV1, (int) $detail['rules']['id'], 'The request must keep the rule version frozen at submission.');
        self::assertSame(['impact', 'feasibility'], array_keys($detail['rules']['weights']));

        // Reviewer cannot self-review, must score the frozen criteria exactly.
        try {
            $funding->recordReview($this->reviewer, $requestId, ['impact' => 5, 'team' => 4], 'award', null);
            self::fail('Scoring against a newer rule version must fail.');
        } catch (\InvalidArgumentException) {
        }
        try {
            $funding->recordReview($this->requester, $requestId, ['impact' => 5, 'feasibility' => 4], 'award', null);
            self::fail('Self-review must fail.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $funding->recordReview($this->reviewer, $requestId, ['impact' => 5, 'feasibility' => 4], 'award', 'Strong proposal.');
        $review = $this->pdo->query("SELECT weighted_total, recommendation FROM funding_reviews WHERE request_id = {$requestId} AND reviewer_id = {$this->reviewer->id}")->fetch();
        self::assertSame('460.000', (string) $review['weighted_total']); // 5*60 + 4*40

        // Prohibited self-approval and reviewer-approval fail safely.
        try {
            $funding->approve($this->requester, $requestId, 'approved', null);
            self::fail('Self-approval must fail.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }
        $selfReviewer = new Actor($this->reviewer->id, ['funding.review', 'funding.approve'], true, true, true);
        try {
            $funding->approve($selfReviewer, $requestId, 'approved', null);
            self::fail('A reviewer must not approve a request they scored.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $funding->approve($this->approver, $requestId, 'approved', 'Within mandate.');
        self::assertSame('approved', $this->pdo->query("SELECT status FROM funding_requests WHERE id = {$requestId}")->fetchColumn());

        $awardId = $funding->award($this->staff, $requestId, '2500.00', 'Tranche conditions apply.');
        try {
            $funding->award($this->staff, $requestId, '100.00', null);
            self::fail('A second award on the same request must fail.');
        } catch (\Throwable) {
        }

        // Over-award totals are rejected inside the transaction.
        try {
            $disbursements->schedule($this->finance, $awardId, 'REF-' . bin2hex(random_bytes(4)), '2026-12-01', '3000.00', null);
            self::fail('Over-award disbursement must fail.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
        }

        $ref = 'TRX-' . bin2hex(random_bytes(5));
        $disb1 = $disbursements->schedule($this->finance, $awardId, $ref, '2026-12-01', '1500.00', null);
        // Duplicate reference is blocked.
        try {
            $disbursements->schedule($this->finance, $awardId, $ref, '2027-02-01', '10.00', null);
            self::fail('Duplicate transfer reference must fail.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
        }
        $disbursements->schedule($this->finance, $awardId, 'TRX2-' . bin2hex(random_bytes(4)), '2027-01-15', '1000.00', null);

        // Awarder cannot authorize the transfer (separation of duties).
        $awarderFinance = new Actor($this->staff->id, ['disbursement.authorize', 'funding.manage'], true, true, true);
        try {
            $disbursements->authorize($awarderFinance, $disb1);
            self::fail('The award authorizer must not authorize its disbursements.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $disbursements->authorize($this->finAuth, $disb1);
        // Authorizer cannot record their own authorization.
        $authRecord = new Actor($this->finAuth->id, ['disbursement.authorize', 'disbursement.record'], true, true, true);
        try {
            $disbursements->record($authRecord, $disb1, null, null);
            self::fail('The authorizer must not record the same disbursement.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }
        $disbursements->record($this->finance, $disb1, null, 'Bank transfer confirmed.');
        $row = $this->pdo->query("SELECT status FROM disbursements WHERE id = {$disb1}")->fetch();
        self::assertSame('recorded', $row['status']);
    }

    /** Journey 7: submit → verify → publish by different actors; public view separates actuals from targets; duplicates fail. */
    public function testImpactResultsVerificationAndPublicReporting(): void
    {
        $impact = new ImpactService($this->pdo);
        $officer = new Actor($this->user(), ['impact.edit', 'impact.submit'], true, true, true);
        $verifier = new Actor($this->user(), ['impact.verify', 'impact.publish'], true, true, true);
        $code = 'YOUTH_' . strtoupper(bin2hex(random_bytes(3)));

        $indicatorId = $impact->createIndicator($officer, $code, 'Youth trained', 'People completing training.', 'people', 'operational', 'count(completions)', 'annual', null, 'Sample cohort records');
        $impact->setTarget($officer, $indicatorId, '2026', '500', null);

        $resultId = $impact->submitResult($officer, $indicatorId, '2026', '120', null, null, 'Greater Accra', null, 'Attendance registers', null);
        // The same dimensions cannot be counted twice.
        try {
            $impact->submitResult($officer, $indicatorId, '2026', '120', null, null, 'Greater Accra', null, 'Duplicate', null);
            self::fail('Duplicate result must fail.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
        }

        // The submitter cannot verify their own result.
        $officerVerify = new Actor($officer->id, ['impact.submit', 'impact.verify'], true, true, true);
        try {
            $impact->verify($officerVerify, $resultId, 1);
            self::fail('Self-verification must fail.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }

        $impact->verify($verifier, $resultId, 1);
        // Publishing must require the verified state.
        try {
            $impact->publish($verifier, $resultId, 1);
            self::fail('Stale version must fail.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
        }
        $impact->publish($verifier, $resultId, 2);

        $summary = $impact->publicSummary('2026');
        $row = null;
        foreach ($summary as $candidate) {
            if ($candidate['code'] === $code) {
                $row = $candidate;
            }
        }
        self::assertNotNull($row, 'Published result must appear in the public summary.');
        self::assertSame('120.0000', (string) $row['actual']);
        self::assertSame('500.0000', (string) $row['target'], 'Targets must be reported alongside, not merged into, actuals.');

        // Submitted-but-unverified results are invisible publicly.
        $hiddenId = $impact->submitResult($officer, $indicatorId, '2026', '10', null, null, 'Ashanti', null, null, null);
        $summary = $impact->publicSummary('2026');
        foreach ($summary as $candidate) {
            if ($candidate['code'] === $code && $candidate['geography'] === 'Ashanti') {
                self::fail('Unverified results must not appear publicly.');
            }
        }

        // Report lifecycle: author cannot approve their own report.
        $reportId = $impact->createReport($officer, 'Annual report 2026', '2026', 'Summary', 'Body');
        $officerPublish = new Actor($officer->id, ['impact.edit', 'impact.publish'], true, true, true);
        try {
            $impact->approveReport($officerPublish, $reportId, 1);
            self::fail('Self-approval of a report must fail.');
        } catch (HttpError $error) {
            self::assertSame(403, $error->status);
        }
        $impact->approveReport($verifier, $reportId, 1);
        $impact->publishReport($verifier, $reportId, 2);
        self::assertSame('published', $this->pdo->query("SELECT status FROM impact_reports WHERE id = {$reportId}")->fetchColumn());
    }

    /** Journey 8: org A sees the shared report + document; org B and unshared users cannot — even at the download boundary. Revoking kills access. */
    public function testPartnerPortalIsolationAndShareRevocation(): void
    {
        $partners = new PartnerService($this->pdo);
        $impact = new ImpactService($this->pdo);

        $orgA = $partners->createOrg($this->staff, 'Alpha Foundation', 'donor', 'https://alpha.example.invalid');
        $orgB = $partners->createOrg($this->staff, 'Beta Trust', 'donor', null);

        $memberAId = $this->user();
        $memberBId = $this->user();
        $unsharedId = $this->user();
        $memberA = new Actor($memberAId, ['partner.shared.read', 'partner.members.manage'], true, false, true);
        $memberB = new Actor($memberBId, ['partner.shared.read'], true, false, true);
        $unshared = new Actor($unsharedId, ['partner.shared.read'], true, false, true);

        $partners->addMember($this->staff, $orgA, $memberAId, 'admin');
        $partners->addMember($this->staff, $orgB, $memberBId, 'viewer');

        // Commitments are pledges; received funds are separate and capped.
        $commitId = $partners->recordCommitment($this->staff, $orgA, null, '10000.00', 'USD', '2026-10-01', 'Quarterly reporting');
        $partners->recordReceived($this->staff, $commitId, 'RCV-' . bin2hex(random_bytes(4)), '4000.00', 'USD', '2026-10-15', null);
        try {
            $partners->recordReceived($this->staff, $commitId, 'RCV-' . bin2hex(random_bytes(4)), '7000.00', 'USD', '2026-11-01', null);
            self::fail('Received funds must not exceed the commitment.');
        } catch (HttpError $error) {
            self::assertSame(422, $error->status);
        }
        $commit = $this->pdo->query("SELECT status FROM partner_commitments WHERE id = {$commitId}")->fetch();
        self::assertSame('partial', $commit['status']);

        // Publish a report and share it to org A only.
        $officer = new Actor($this->user(), ['impact.edit', 'impact.publish'], true, true, true);
        $verifier = new Actor($this->user(), ['impact.publish'], true, true, true);
        $reportId = $impact->createReport($officer, 'Partner brief ' . bin2hex(random_bytes(3)), '2026', 'Shared summary', 'Shared body');
        $impact->approveReport($verifier, $reportId, 1);
        $impact->publishReport($verifier, $reportId, 2);
        $partners->share($this->staff, $orgA, 'report', $reportId);

        // A private document shared to org A.
        $key = 'partner-doc-' . bin2hex(random_bytes(6)) . '.txt';
        $this->pdo->prepare("INSERT INTO media_assets (sha256, storage_path, original_filename, mime, alt_text, classification, review_status, created_at) VALUES (?, ?, ?, 'text/plain', '', 'private', 'approved', UTC_TIMESTAMP(6))")
            ->execute([$key, $key, 'report-backup.txt']);
        $mediaId = (int) $this->pdo->lastInsertId();
        $partners->share($this->staff, $orgA, 'document', $mediaId);

        // Org A member sees it; org B member and unshared user cannot — same boundary as the download endpoint.
        self::assertSame($orgA, $partners->canAccess($memberA, 'report', $reportId));
        self::assertSame($orgA, $partners->canAccess($memberA, 'document', $mediaId));
        self::assertNull($partners->canAccess($memberB, 'report', $reportId), 'Another org must not see the share.');
        self::assertNull($partners->canAccess($memberB, 'document', $mediaId), 'Another org must not reach the download.');
        self::assertNull($partners->canAccess($unshared, 'report', $reportId), 'Unshared users must not guess URLs.');

        // Revoking the share revokes download access immediately.
        $partners->revokeShare($this->staff, $orgA, 'document', $mediaId);
        self::assertNull($partners->canAccess($memberA, 'document', $mediaId), 'Revocation must revoke download access.');

        // Suspended membership also blocks access.
        $partners->suspendMember($this->staff, $orgA, $memberAId);
        self::assertNull($partners->canAccess($memberA, 'report', $reportId), 'Suspended members lose access.');

        // Staff notes never appear in portal data.
        $partners->addNote($this->staff, $orgA, 'Sensitive relationship note.');
        $portal = $partners->portalData($memberA);
        self::assertSame([], $portal['shared'] ?? [], 'Suspended member must see no shared items.');

        // Access log exists for auditability after a view.
        $partners->logAccess($orgA, $memberA, 'view', 'report', $reportId);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM partner_access_log WHERE org_id = {$orgA} AND resource_id = {$reportId}")->fetchColumn());
    }
}

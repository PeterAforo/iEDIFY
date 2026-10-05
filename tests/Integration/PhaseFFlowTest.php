<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\SecretBox;
use IEdify\Modules\CMS\Services\CmsService;
use IEdify\Modules\Identity\Services\DataRequestService;
use IEdify\Modules\Identity\Services\InvitationService;
use PHPUnit\Framework\TestCase;

/** Remaining-platform flows: data requests, staff invitations and scheduled publishing. */
final class PhaseFFlowTest extends TestCase
{
    private \PDO $pdo;
    private Actor $admin;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->pdo = Connection::open(Config::load($root, true));
        $this->admin = new Actor($this->user(), ['identity.manage', 'cms.edit', 'cms.review', 'cms.publish'], true, true, true);
    }

    private function user(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'User ' . substr($id, 0, 6), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testDataRequestLifecycle(): void
    {
        $service = new DataRequestService($this->pdo);
        $member = new Actor($this->user(), [], true, false, true);

        $service->request($member, 'export');
        try {
            $service->request($member, 'export');
            $this->fail('Duplicate pending export must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $row = null;
        foreach ($service->pending() as $item) {
            if ((int) $item['user_id'] === $member->id) {
                $row = $item;
            }
        }
        $this->assertNotNull($row, 'Pending export request must be listed.');

        try {
            $service->decide($member, (int) $row['id'], 'completed');
            $this->fail('Deciding requires identity.manage.');
        } catch (HttpError $error) {
            $this->assertSame(403, $error->status);
        }

        $service->request($member, 'deletion');
        $deletion = array_values(array_filter($service->pending(), fn (array $item): bool => (int) $item['user_id'] === $member->id && $item['type'] === 'deletion'))[0];
        $service->decide($this->admin, (int) $deletion['id'], 'completed');

        $status = $this->pdo->prepare('SELECT status FROM users WHERE id = ?');
        $status->execute([$member->id]);
        $this->assertSame('deactivated', $status->fetchColumn(), 'Completed deletion must deactivate the account.');

        $audit = $this->pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'data_request.%' AND entity_id IN (?, ?)");
        $audit->execute([(string) $row['id'], (string) $deletion['id']]);
        $this->assertGreaterThanOrEqual(2, (int) $audit->fetchColumn(), 'Data-request mutations must be audited.');
    }

    public function testInvitationCreateAcceptAndExpiry(): void
    {
        $service = new InvitationService($this->pdo, new SecretBox(base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
        $roleId = (int) $this->pdo->query('SELECT id FROM roles WHERE privileged = 1 LIMIT 1')->fetchColumn();
        $this->assertGreaterThan(0, $roleId);

        $email = bin2hex(random_bytes(8)) . '@example.invalid';
        $token = $service->invite($this->admin, $email, $roleId);
        $this->assertSame(64, strlen($token));

        // Outbox event carries the encrypted token, never plaintext.
        $event = $this->pdo->prepare("SELECT payload FROM outbox_events WHERE event_type = 'identity.invite' ORDER BY id DESC LIMIT 1");
        $event->execute();
        $payload = (string) $event->fetchColumn();
        $this->assertStringNotContainsString($token, $payload);

        try {
            $service->invite(new Actor($this->user(), [], true, false, true), bin2hex(random_bytes(8)) . '@example.invalid', $roleId);
            $this->fail('Invitation requires identity.manage.');
        } catch (HttpError $error) {
            $this->assertSame(403, $error->status);
        }

        $invite = $service->preview($token);
        $this->assertSame($email, $invite['email']);

        $userId = $service->accept($token, 'Invited Coordinator', 'a-very-long-password');
        $role = $this->pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
        $role->execute([$userId]);
        $this->assertSame($roleId, (int) $role->fetchColumn(), 'Accepted invitation must assign the invited role.');

        try {
            $service->accept($token, 'Second Attempt', 'a-very-long-password');
            $this->fail('Invitation tokens are single-use.');
        } catch (HttpError $error) {
            $this->assertSame(404, $error->status);
        }

        // Expired invitation cannot be accepted.
        $token2 = $service->invite($this->admin, bin2hex(random_bytes(8)) . '@example.invalid', $roleId);
        $this->pdo->prepare('UPDATE invitations SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 DAY WHERE token_hash = ?')->execute([hash('sha256', $token2)]);
        try {
            $service->accept($token2, 'Too Late', 'a-very-long-password');
            $this->fail('Expired invitations must be rejected.');
        } catch (HttpError $error) {
            $this->assertSame(404, $error->status);
        }
    }

    public function testScheduledPublishingHonoursFlagGate(): void
    {
        $cms = new CmsService($this->pdo);
        $slug = '/scheduled-' . bin2hex(random_bytes(4));
        $id = $cms->create($this->admin, 'news', $slug, 'Scheduled story', [['type' => 'text', 'text' => 'Body']]);
        $content = fn (): array => (array) $this->pdo->query('SELECT * FROM content_items WHERE id = ' . $id)->fetch();

        try {
            $cms->schedule($this->admin, $id, (int) $content()['version'], gmdate('Y-m-d\TH:i', strtotime('-1 minute')));
            $this->fail('Only items in review can be scheduled.');
        } catch (HttpError) {
        }

        $cms->submitReview($this->admin, $id, (int) $content()['version']);
        $cms->schedule($this->admin, $id, (int) $content()['version'], gmdate('Y-m-d\TH:i', strtotime('-1 minute')));

        // Open editorial flag must block the scheduled publish.
        $key = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO import_runs (source_system, source_checksum, status, summary, started_at) VALUES ('test', ?, 'completed', '{}', UTC_TIMESTAMP(6))")->execute([hash('sha256', $key)]);
        $runId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO source_records (source_system, source_key, source_url, record_type, source_checksum, raw_record, captured_at, created_at) VALUES ('test', ?, 'test://local', 'page', ?, '{}', UTC_DATE(), UTC_TIMESTAMP(6))")->execute([$key, hash('sha256', $key . 'r')]);
        $sourceId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO source_mappings (source_system, source_key, source_record_id, content_id, import_run_id) VALUES ('test', ?, ?, ?, ?)")->execute([$key, $sourceId, $id, $runId]);
        $this->pdo->prepare("INSERT INTO editorial_flags (source_record_id, code, message, status, created_at) VALUES (?, 'needs_review', 'Imported flag', 'open', UTC_TIMESTAMP(6))")->execute([$sourceId]);

        $this->assertSame(0, $cms->publishDueSchedules(), 'Flagged scheduled items must not be published.');

        $this->pdo->prepare("UPDATE editorial_flags SET status = 'resolved' WHERE source_record_id = ?")->execute([$sourceId]);
        $this->assertSame(1, $cms->publishDueSchedules(), 'Due scheduled item must publish once unblocked.');
        $this->assertSame('published', $content()['status']);
        $this->assertNull($content()['publish_at'], 'publish_at is cleared on publish.');
    }

    public function testFundingRoundCustomFormValidation(): void
    {
        $funding = new \IEdify\Modules\Funding\Services\FundingService($this->pdo);
        $admin = new Actor($this->user(), ['funding.manage'], true, true, true);
        $requester = new Actor($this->user(), ['startup.team'], true, false, true);

        $roundId = $funding->createRound($admin, 'form-' . bin2hex(random_bytes(4)), 'Form Round', 'Custom form', 'USD', '1000.00', gmdate('Y-m-d H:i:s', strtotime('-1 day')), gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $funding->createRuleVersion($admin, $roundId, ['impact' => 100], 5);
        $funding->setRoundForm($admin, $roundId, [
            ['key' => 'stage', 'label' => 'Venture stage', 'type' => 'select', 'required' => true, 'options' => ['idea', 'growth']],
            ['key' => 'team_size', 'label' => 'Team size', 'type' => 'number', 'required' => true],
        ]);
        $funding->setRoundStatus($admin, $roundId, 'open', 2);

        // Missing required answers rejected.
        try {
            $funding->submitRequest($requester, $roundId, 'No answers', '500.00', null);
            $this->fail('Required custom answers must be validated.');
        } catch (\InvalidArgumentException) {
        }
        // Invalid select option rejected.
        try {
            $funding->submitRequest($requester, $roundId, 'Bad option', '500.00', null, null, ['stage' => 'bogus', 'team_size' => '4']);
            $this->fail('Invalid select answers must be rejected.');
        } catch (\InvalidArgumentException) {
        }
        $id = $funding->submitRequest($requester, $roundId, 'Valid answers', '500.00', null, null, ['stage' => 'growth', 'team_size' => '4']);
        $answers = $this->pdo->prepare('SELECT answers FROM funding_requests WHERE id = ?');
        $answers->execute([$id]);
        $decoded = json_decode((string) $answers->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('growth', $decoded['stage']);
        $this->assertSame('4', $decoded['team_size']);
    }

    public function testImpactSmallGroupSuppression(): void
    {
        $impact = new \IEdify\Modules\Impact\Services\ImpactService($this->pdo);
        $officer = new Actor($this->user(), ['impact.edit', 'impact.submit'], true, true, true);
        $verifier = new Actor($this->user(), ['impact.verify', 'impact.publish'], true, true, true);
        $code = 'SUP_' . strtoupper(bin2hex(random_bytes(4)));
        $indicator = $impact->createIndicator($officer, $code, 'Suppression probe', 'Count', 'survey', 'manual', 'count', 'annual', null, null);

        // A disaggregated result must carry its respondent count.
        try {
            $impact->submitResult($officer, $indicator, '2027', '3', null, null, null, ['sex' => 'female'], null, null);
            $this->fail('Disaggregated results require group_size.');
        } catch (\InvalidArgumentException) {
        }

        $small = $impact->submitResult($officer, $indicator, '2027', '3', null, null, 'Accra', ['sex' => 'female'], null, null, 3);
        $large = $impact->submitResult($officer, $indicator, '2027', '40', null, null, 'Accra', ['sex' => 'male'], null, null, 20);
        foreach ([$small, $large] as $id) {
            $impact->verify($verifier, $id, 1);
            $impact->publish($verifier, $id, 2);
        }

        $breakdown = $impact->publicBreakdown('2027', 5);
        $this->assertGreaterThanOrEqual(1, $breakdown['suppressed'], 'The 3-respondent group must be suppressed.');
        $rows = array_values(array_filter($breakdown['rows'], fn (array $r): bool => $r['code'] === $code));
        $this->assertCount(1, $rows);
        $this->assertSame('male', $rows[0]['disaggregation']['sex']);
        $this->assertSame('40.0000', (string) $rows[0]['value']);

        // Aggregate summaries still count every published result.
        $summary = $impact->publicSummary('2027');
        $row = array_values(array_filter($summary, fn (array $r): bool => $r['code'] === $code))[0];
        $this->assertSame('43.0000', (string) $row['actual'], 'Suppressed groups still count toward the total.');
    }

    public function testPublicationMetadataAndDocumentBlock(): void
    {
        $cms = new CmsService($this->pdo);
        $id = $cms->create($this->admin, 'publication', '/pub-' . bin2hex(random_bytes(4)), 'Annual report', [['type' => 'text', 'text' => 'Summary']]);
        $version = (int) $this->pdo->query('SELECT version FROM content_items WHERE id = ' . $id)->fetchColumn();

        try {
            $cms->updateMeta($this->admin, $id, $version, 'Annual report', 'year-one');
            $this->fail('Non-numeric year must be rejected.');
        } catch (\InvalidArgumentException) {
        }
        $cms->updateMeta($this->admin, $id, $version, 'Annual report', '2025');
        $meta = $this->pdo->query('SELECT category, pub_year FROM content_items WHERE id = ' . $id)->fetch();
        $this->assertSame('Annual report', $meta['category']);
        $this->assertSame('2025', (string) $meta['pub_year']);

        // Document blocks validate like other section types.
        $sections = (new \IEdify\Modules\CMS\Services\Sections())->validate([['type' => 'document', 'media_id' => 7, 'label' => 'Download PDF']]);
        $this->assertSame('document', $sections[0]['type']);
        try {
            (new \IEdify\Modules\CMS\Services\Sections())->validate([['type' => 'document', 'media_id' => 'x', 'label' => 'x']]);
            $this->fail('Document blocks need a numeric media_id.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testNewsletterBroadcastBatchingAndDedupe(): void
    {
        $service = new \IEdify\Modules\Engagement\Services\NewsletterService($this->pdo, new SecretBox(base64_encode(str_repeat('n', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
        $operator = new \IEdify\Core\Security\Actor(0, ['operations.manage'], true, true, true);
        foreach (range(1, 3) as $i) {
            $this->pdo->prepare("INSERT INTO newsletter_subscriptions (email, status, confirmed_at, created_at) VALUES (?, 'subscribed', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute(['bcast-' . bin2hex(random_bytes(6)) . '@example.invalid']);
        }
        $subscribed = (int) $this->pdo->query("SELECT COUNT(*) FROM newsletter_subscriptions WHERE status = 'subscribed'")->fetchColumn();
        $this->assertGreaterThanOrEqual(3, $subscribed);

        try {
            $service->queueBroadcast(new Actor($this->user(), [], true, false, true), 'S', 'B');
            $this->fail('Broadcasts require operations.manage.');
        } catch (HttpError $error) {
            $this->assertSame(403, $error->status);
        }

        $first = $service->queueBroadcast($operator, 'Update ' . bin2hex(random_bytes(3)), 'Broadcast body.', 2);
        $this->assertSame(2, $first['queued'], 'Batch limit must cap the queue.');
        // Re-running the same broadcast continues where it left off — never duplicates.
        $rest = $service->queueBroadcastBatch($operator, $first['id'], 1000);
        $this->assertSame($subscribed - 2, $rest);
        $this->assertSame(0, $service->queueBroadcastBatch($operator, $first['id'], 1000), 'Fully queued broadcast must not re-queue.');

        $events = $this->pdo->prepare("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'newsletter.broadcast' AND event_key LIKE ?");
        $events->execute(['newsletter.broadcast.' . $first['id'] . '.%']);
        $this->assertSame($subscribed, (int) $events->fetchColumn(), 'Every subscribed address gets exactly one deduplicated event.');

        // Payloads carry ciphertext unsubscribe tokens, never plaintext.
        $payload = $this->pdo->query("SELECT payload FROM outbox_events WHERE event_type = 'newsletter.broadcast' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $decoded = json_decode((string) $payload, true, 8, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('unsubscribe_ciphertext', $decoded);
        $this->assertStringNotContainsString('/newsletter/unsubscribe/', (string) $payload);
    }

    public function testOutboxRetryRequeuesOnlyFailed(): void
    {
        $this->pdo->prepare("INSERT INTO outbox_events (event_key, event_type, payload, status, available_at, created_at) VALUES (?, 'identity.invite', '{}', 'failed', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['retry-test-' . bin2hex(random_bytes(8))]);
        $id = (int) $this->pdo->lastInsertId();
        $retry = $this->pdo->prepare("UPDATE outbox_events SET status = 'pending', attempts = 0 WHERE id = ? AND status = 'failed'");
        $retry->execute([$id]);
        $this->assertSame(1, $retry->rowCount());
        $retry->execute([$id]);
        $this->assertSame(0, $retry->rowCount(), 'A re-queued event cannot be retried a second time.');
    }
}

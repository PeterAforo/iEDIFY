<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Jobs\OutboxProcessor;
use IEdify\Core\Security\SecretBox;
use IEdify\Modules\Web\Services\SearchService;
use IEdify\Services\Backup\BackupService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PhaseEFlowTest extends TestCase
{
    private \PDO $pdo;
    private Config $config;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->config = Config::load($this->root, true);
        $this->pdo = Connection::open($this->config);
    }

    private function user(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'User ' . substr($id, 0, 6), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function processor(): OutboxProcessor
    {
        return new OutboxProcessor($this->pdo, $this->config, new SecretBox($this->config->string('APP_KEY')), new NullLogger(), $this->root);
    }

    /** Sweep unrelated queued events so processor stats are deterministic. */
    private function quiesceOutbox(): void
    {
        $this->pdo->exec("UPDATE outbox_events SET status = 'failed', lease_token = NULL, lease_until = NULL WHERE status IN ('pending','processing')");
    }

    /** Journey 11: processing the same event twice must not deliver twice. */
    public function testOutboxRetriesDoNotDuplicateDelivery(): void
    {
        $this->quiesceOutbox();
        $userId = $this->user();
        $captureDir = $this->config->string('MAIL_CAPTURE_DIR', $this->root . '/storage/mail');
        $before = count((array) glob($captureDir . '/*.eml'));

        (new Transaction($this->pdo))->run(function () use ($userId): void {
            (new Outbox($this->pdo))->record('test.retry.' . bin2hex(random_bytes(8)), 'notification.send', ['user_id' => $userId, 'subject' => 'Retry check', 'body' => 'Body', 'scope' => 'general', 'link' => '/account']);
        });

        $stats = $this->processor()->run(5);
        self::assertSame(1, $stats['processed']);
        self::assertSame(1, count((array) glob($captureDir . '/*.eml')) - $before, 'Exactly one message must be captured.');

        // Second run: the completed event is not claimed again.
        $stats = $this->processor()->run(5);
        self::assertSame(0, $stats['processed']);
        self::assertSame(1, count((array) glob($captureDir . '/*.eml')) - $before, 'A retry must not deliver a second copy.');
    }

    /** Journey 11: a failing event retries with backoff and fails permanently without duplicating. */
    public function testFailingEventRetriesWithBackoffAndStops(): void
    {
        $this->quiesceOutbox();
        $key = 'test.fail.' . bin2hex(random_bytes(8));
        (new Transaction($this->pdo))->run(function () use ($key): void {
            (new Outbox($this->pdo))->record($key, 'unhandled.type', ['x' => 1]);
        });
        $processor = $this->processor();
        for ($i = 0; $i < 5; $i++) {
            $this->pdo->exec('UPDATE outbox_events SET available_at = UTC_TIMESTAMP(6) WHERE event_key = ' . $this->pdo->quote($key));
            $processor->run(5);
        }
        $row = $this->pdo->query('SELECT status, attempts FROM outbox_events WHERE event_key = ' . $this->pdo->quote($key))->fetch();
        self::assertSame('failed', $row['status']);
        self::assertSame(5, (int) $row['attempts']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM outbox_events WHERE event_key = ' . $this->pdo->quote($key))->fetchColumn());
    }

    /** Journey 12: backup + rehearsed restore round-trips real data. */
    public function testBackupCreateAndRehearsedRestore(): void
    {
        $dir = $this->root . '/storage/private/backups-test-' . bin2hex(random_bytes(4));
        $service = new BackupService();
        try {
            $path = $service->create($this->pdo, $dir);
            self::assertFileExists($path);
            self::assertGreaterThan(1000, filesize($path));

            $prefix = 'rehearse' . bin2hex(random_bytes(3)) . '_';
            $statements = $service->restore($this->pdo, $path, $prefix);
            self::assertGreaterThan(50, $statements);

            // Restored copies must match source row counts for key tables.
            foreach (['users', 'roles', 'content_items', 'funding_requests', 'impact_results', 'partner_organizations'] as $table) {
                $expected = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $actual = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$prefix}{$table}`")->fetchColumn();
                self::assertSame($expected, $actual, "Restored {$table} count mismatch.");
            }
            // Database→file integrity: the rehearsal must resolve exactly the
            // same private-media files as the source — no new dangling refs.
            $storage = rtrim($this->config->string('CONTENT_STORAGE', $this->root . '/storage/private/content'), '/');
            $missing = function (string $table) use ($storage): int {
                $count = 0;
                foreach ($this->pdo->query("SELECT storage_path FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    $path = basename((string) $path);
                    if ($path === '' || !is_file($storage . '/' . $path)) {
                        $count++;
                    }
                }
                return $count;
            };
            self::assertSame($missing('media_assets'), $missing($prefix . 'media_assets'), 'Restore introduced dangling media references.');
        } finally {
            // Drop the rehearsed tables and the temp backup dir.
            foreach ($this->pdo->query("SHOW TABLES LIKE 'rehearse%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            foreach ((array) glob($dir . '/*') as $file) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    /** Journey 11: search returns only publicly visible records. */
    public function testSearchEnforcesVisibility(): void
    {
        $search = new SearchService($this->pdo);
        $token = 'zxq' . bin2hex(random_bytes(6));

        // A draft page with the token must never surface.
        $this->pdo->prepare("INSERT INTO content_items (slug, title, content_type, status, created_at, updated_at) VALUES (?, ?, 'page', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['/draft-' . substr($token, 0, 8), 'Draft ' . $token]);
        // An unpublished event must not surface; a published one must.
        $this->pdo->prepare("INSERT INTO events (slug, title, description, location, starts_at, status, created_at, updated_at) VALUES (?, ?, 'Contains {$token}', 'Accra', UTC_TIMESTAMP(6), 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['evt-' . substr($token, 0, 8), 'Draft event ' . $token]);
        $this->pdo->prepare("INSERT INTO events (slug, title, description, location, starts_at, status, created_at, updated_at) VALUES (?, ?, 'Contains {$token}', 'Accra', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY), 'published', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['evtpub-' . substr($token, 0, 8), 'Public event ' . $token]);

        $results = $search->publicSearch($token);
        $titles = array_column($results['results'], 'title');
        self::assertContains('Public event ' . $token, $titles);
        self::assertNotContains('Draft event ' . $token, $titles);
        self::assertNotContains('Draft ' . $token, $titles);
        self::assertSame([], $search->publicSearch('a')['results'], 'Queries under two characters return nothing.');
    }
}

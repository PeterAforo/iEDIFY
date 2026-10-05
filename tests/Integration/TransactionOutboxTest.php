<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use Dotenv\Dotenv;
use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Jobs\Outbox;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionOutboxTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/.env.test')) {
            self::markTestSkipped('Dedicated MySQL test credentials are not configured.');
        }
        $values = Dotenv::parse((string) file_get_contents($root . '/.env.test'));
        if (($values['APP_ENV'] ?? '') !== 'test' || !preg_match('/_test$/D', $values['DB_DATABASE'] ?? '') || ($values['MAIL_LIVE_ENABLED'] ?? '') !== 'false') {
            throw new RuntimeException('Unsafe integration test configuration.');
        }
        $this->pdo = Connection::open(new Config($values));
    }

    public function testOutboxIsRolledBackWithBusinessAction(): void
    {
        $key = 'test.rollback.' . bin2hex(random_bytes(16));
        try {
            (new Transaction($this->pdo))->run(function () use ($key): void {
                (new Outbox($this->pdo))->record($key, 'test.event', ['record_id' => 1]);
                throw new RuntimeException('Abort business action');
            });
            self::fail('Expected rollback.');
        } catch (RuntimeException $error) {
            self::assertSame('Abort business action', $error->getMessage());
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM outbox_events WHERE event_key = ?');
        $statement->execute([$key]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testDuplicateEventKeysDoNotEnqueueTwice(): void
    {
        $this->pdo->beginTransaction();
        try {
            $key = 'test.dedup.' . bin2hex(random_bytes(16));
            $outbox = new Outbox($this->pdo);
            $outbox->record($key, 'test.event', ['record_id' => 1]);
            $outbox->record($key, 'test.event', ['record_id' => 1]);
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM outbox_events WHERE event_key = ?');
            $statement->execute([$key]);
            self::assertSame(1, (int) $statement->fetchColumn());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testOutboxRefusesNonTransactionalWrites(): void
    {
        $this->expectException(\LogicException::class);
        (new Outbox($this->pdo))->record('unsafe', 'test.event', []);
    }
}

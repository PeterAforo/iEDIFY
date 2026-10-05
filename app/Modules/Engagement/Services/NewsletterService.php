<?php

declare(strict_types=1);

namespace IEdify\Modules\Engagement\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Jobs\Outbox;
use IEdify\Core\Security\SecretBox;
use InvalidArgumentException;
use PDO;

final readonly class NewsletterService
{
    public function __construct(private PDO $pdo, private SecretBox $secrets)
    {
    }

    /**
     * Start or refresh a double opt-in subscription. Returns a status string;
     * never reveals whether a different address is already subscribed.
     */
    public function subscribe(string $email, ?int $userId): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        return (new Transaction($this->pdo))->run(function () use ($email, $userId): string {
            $statement = $this->pdo->prepare('SELECT id, status FROM newsletter_subscriptions WHERE email = ? FOR UPDATE');
            $statement->execute([$email]);
            $existing = $statement->fetch();
            if ($existing !== false && $existing['status'] === 'subscribed') {
                return 'subscribed';
            }
            if ($existing === false) {
                $this->pdo->prepare("INSERT INTO newsletter_subscriptions (email, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))")->execute([$email, $userId]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $id = (int) $existing['id'];
                $this->pdo->prepare("UPDATE newsletter_subscriptions SET status = 'pending', unsubscribed_at = NULL, user_id = COALESCE(?, user_id) WHERE id = ?")->execute([$userId, $id]);
            }
            $confirm = $this->issueToken($id, 'confirm', '7 DAY');
            $unsubscribe = $this->issueToken($id, 'unsubscribe', '400 DAY');
            (new Outbox($this->pdo))->record('newsletter.confirm.' . $id . '.' . substr(hash('sha256', $confirm), 0, 12), 'newsletter.confirm', [
                'subscription_id' => $id,
                'confirm_ciphertext' => $this->secrets->encrypt($confirm),
                'unsubscribe_ciphertext' => $this->secrets->encrypt($unsubscribe),
            ]);
            (new AuditLog($this->pdo))->record($userId, 'newsletter.subscribe_requested', 'newsletter_subscription', (string) $id);
            return 'pending';
        });
    }

    public function confirm(string $token): bool
    {
        return $this->consume($token, 'confirm', function (int $subscriptionId): void {
            $this->pdo->prepare("UPDATE newsletter_subscriptions SET status = 'subscribed', confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP(6)) WHERE id = ? AND status = 'pending'")->execute([$subscriptionId]);
        });
    }

    public function unsubscribe(string $token): bool
    {
        return $this->consume($token, 'unsubscribe', function (int $subscriptionId): void {
            $this->pdo->prepare("UPDATE newsletter_subscriptions SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'subscribed'")->execute([$subscriptionId]);
        });
    }

    private function issueToken(int $subscriptionId, string $purpose, string $interval): string
    {
        $this->pdo->prepare('UPDATE newsletter_tokens SET consumed_at = UTC_TIMESTAMP(6) WHERE subscription_id = ? AND purpose = ? AND consumed_at IS NULL')->execute([$subscriptionId, $purpose]);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare("INSERT INTO newsletter_tokens (subscription_id, purpose, token_hash, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$interval}), UTC_TIMESTAMP(6))")->execute([$subscriptionId, $purpose, hash('sha256', $token)]);
        return $token;
    }

    private function consume(string $token, string $purpose, callable $effect): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }
        return (new Transaction($this->pdo))->run(function () use ($token, $purpose, $effect): bool {
            $statement = $this->pdo->prepare('SELECT t.id, t.subscription_id FROM newsletter_tokens t JOIN newsletter_subscriptions s ON s.id = t.subscription_id WHERE t.token_hash = ? AND t.purpose = ? AND t.consumed_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6) FOR UPDATE');
            $statement->execute([hash('sha256', $token), $purpose]);
            $record = $statement->fetch();
            if ($record === false) {
                return false;
            }
            $this->pdo->prepare('UPDATE newsletter_tokens SET consumed_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$record['id']]);
            $effect((int) $record['subscription_id']);
            (new AuditLog($this->pdo))->record(null, 'newsletter.' . $purpose . '_confirmed', 'newsletter_subscription', (string) $record['subscription_id']);
            return true;
        });
    }
}

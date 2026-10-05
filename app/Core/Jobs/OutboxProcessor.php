<?php

declare(strict_types=1);

namespace IEdify\Core\Jobs;

use IEdify\Core\Config;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Mail\Mailer;
use IEdify\Core\Security\SecretBox;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims pending outbox events under a lease, dispatches them through the
 * configured transports and marks completion only after the transport
 * accepted the message. Failed attempts retry with quadratic backoff.
 */
final class OutboxProcessor
{
    private const MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 300;

    public function __construct(
        private PDO $pdo,
        private Config $config,
        private SecretBox $secrets,
        private LoggerInterface $logger,
        private string $root,
    ) {
    }

    public function run(int $limit = 20): array
    {
        $this->pdo->exec("UPDATE outbox_events SET status = 'pending', lease_token = NULL, lease_until = NULL WHERE status = 'processing' AND lease_until < UTC_TIMESTAMP(6)");
        $stats = ['processed' => 0, 'failed' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $event = $this->claim();
            if ($event === null) {
                break;
            }
            try {
                $this->dispatch($event);
                $this->complete((int) $event['id'], (string) $event['lease']);
                $stats['processed']++;
            } catch (Throwable $error) {
                $this->fail($event, $error);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    private function claim(): ?array
    {
        return (new Transaction($this->pdo))->run(function (): ?array {
            $statement = $this->pdo->prepare("SELECT id, event_type, payload, attempts FROM outbox_events WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP(6) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $statement->execute();
            $event = $statement->fetch();
            if ($event === false) {
                return null;
            }
            $lease = bin2hex(random_bytes(32));
            $this->pdo->prepare("UPDATE outbox_events SET status = 'processing', lease_token = ?, lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), attempts = attempts + 1 WHERE id = ?")->execute([$lease, self::LEASE_SECONDS, $event['id']]);
            $event['lease'] = $lease;
            $event['payload'] = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
            return $event;
        });
    }

    private function dispatch(array $event): void
    {
        $payload = $event['payload'];
        switch ($event['event_type']) {
            case 'identity.verify_email':
                $email = $this->userEmail((int) $payload['user_id']);
                $token = $this->secrets->decrypt($payload['token_ciphertext']);
                $this->mailer()->send($email, 'Verify your iEDIFY Africa email', 'verify-email', [
                    'url' => '/verify-email/' . $token,
                ]);
                break;
            case 'identity.password_reset':
                $email = $this->userEmail((int) $payload['user_id']);
                $token = $this->secrets->decrypt($payload['token_ciphertext']);
                $this->mailer()->send($email, 'Reset your iEDIFY Africa password', 'password-reset', [
                    'url' => '/password/reset/' . $token,
                ]);
                break;
            case 'enquiry.notify':
                $statement = $this->pdo->prepare('SELECT name, email, subject, message FROM enquiries WHERE id = ?');
                $statement->execute([(int) $payload['enquiry_id']]);
                $enquiry = $statement->fetch();
                if ($enquiry === false) {
                    throw new \RuntimeException('Enquiry record missing for notification.');
                }
                $this->mailer()->send($this->config->string('MAIL_ENQUIRIES_TO', $this->config->string('MAIL_FROM_ADDRESS')), 'Website enquiry: ' . $enquiry['subject'], 'enquiry-notify', [
                    'enquiry' => $enquiry,
                ]);
                break;
            case 'newsletter.confirm':
                $statement = $this->pdo->prepare('SELECT email FROM newsletter_subscriptions WHERE id = ?');
                $statement->execute([(int) $payload['subscription_id']]);
                $email = $statement->fetchColumn();
                if (!is_string($email)) {
                    throw new \RuntimeException('Subscription record missing for confirmation.');
                }
                $this->mailer()->send($email, 'Confirm your iEDIFY Africa subscription', 'newsletter-confirm', [
                    'confirm_url' => '/newsletter/confirm/' . $this->secrets->decrypt($payload['confirm_ciphertext']),
                    'unsubscribe_url' => '/newsletter/unsubscribe/' . $this->secrets->decrypt($payload['unsubscribe_ciphertext']),
                ]);
                break;
            case 'notification.send':
                $this->mailer()->send($this->userEmail((int) $payload['user_id']), (string) $payload['subject'], 'notification', [
                    'subject' => (string) $payload['subject'],
                    'body' => (string) $payload['body'],
                    'link' => (string) ($payload['link'] ?? ''),
                ]);
                break;
            default:
                throw new \RuntimeException('No handler for outbox event type.');
        }
    }

    private function complete(int $id, string $lease): void
    {
        $this->pdo->prepare("UPDATE outbox_events SET status = 'completed', processed_at = UTC_TIMESTAMP(6), lease_token = NULL, lease_until = NULL WHERE id = ? AND lease_token = ?")->execute([$id, $lease]);
    }

    private function fail(array $event, Throwable $error): void
    {
        $attempts = (int) $event['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->pdo->prepare("UPDATE outbox_events SET status = 'failed', last_error_code = ?, lease_token = NULL, lease_until = NULL WHERE id = ?")->execute([$error::class, $event['id']]);
        } else {
            $delay = ($attempts ** 2) * 60;
            $this->pdo->prepare("UPDATE outbox_events SET status = 'pending', available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), last_error_code = ?, lease_token = NULL, lease_until = NULL WHERE id = ?")->execute([$delay, $error::class, $event['id']]);
        }
        $this->logger->error('Outbox event failed', ['event_id' => $event['id'], 'event_type' => $event['event_type'], 'exception_class' => $error::class]);
    }

    private function userEmail(int $userId): string
    {
        $statement = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $email = $statement->fetchColumn();
        if (!is_string($email)) {
            throw new \RuntimeException('User record missing for notification.');
        }
        return $email;
    }

    private function mailer(): Mailer
    {
        return new Mailer($this->config, $this->root);
    }
}

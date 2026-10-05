<?php

declare(strict_types=1);

namespace IEdify\Modules\Engagement\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Jobs\Outbox;
use InvalidArgumentException;
use PDO;

final readonly class EnquiryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Persist a contact enquiry and queue staff notification in one
     * transaction. Returns the public reference for the confirmation.
     */
    public function submit(string $name, string $email, string $subject, string $message, ?int $userId): string
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $subject = trim($subject);
        $message = trim($message);
        if ($name === '' || mb_strlen($name) > 180
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || $subject === '' || mb_strlen($subject) > 180
            || mb_strlen($message) < 10 || mb_strlen($message) > 8000
            || str_contains($name . $subject . $message, "\0")) {
            throw new InvalidArgumentException('Check your name, email, subject and message, then try again.');
        }
        $publicId = bin2hex(random_bytes(16));
        (new Transaction($this->pdo))->run(function () use ($publicId, $name, $email, $subject, $message, $userId): void {
            $this->pdo->prepare("INSERT INTO enquiries (public_id, name, email, subject, message, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))")->execute([$publicId, $name, $email, $subject, $message, $userId]);
            (new Outbox($this->pdo))->record('enquiry.notify.' . $publicId, 'enquiry.notify', ['enquiry_id' => (int) $this->pdo->lastInsertId(), 'public_id' => $publicId]);
            (new AuditLog($this->pdo))->record($userId, 'enquiry.submitted', 'enquiry', $publicId);
        });
        return $publicId;
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class EnquiriesNewsletter extends AbstractMigration
{
    public function up(): void
    {
        foreach ([
            "CREATE TABLE enquiries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(32) NOT NULL UNIQUE, name VARCHAR(180) NOT NULL, email VARCHAR(254) NOT NULL, subject VARCHAR(180) NOT NULL, message TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'new', user_id BIGINT UNSIGNED NULL, responder_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, resolved_at DATETIME(6) NULL, FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (responder_id) REFERENCES users(id), INDEX (status, created_at), CHECK (status IN ('new','in_progress','resolved','dismissed'))) ENGINE=InnoDB",
            "CREATE TABLE newsletter_subscriptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL UNIQUE, status VARCHAR(20) NOT NULL DEFAULT 'pending', user_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, confirmed_at DATETIME(6) NULL, unsubscribed_at DATETIME(6) NULL, FOREIGN KEY (user_id) REFERENCES users(id), INDEX (status), CHECK (status IN ('pending','subscribed','unsubscribed'))) ENGINE=InnoDB",
            "CREATE TABLE newsletter_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subscription_id BIGINT UNSIGNED NOT NULL, purpose VARCHAR(30) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (subscription_id) REFERENCES newsletter_subscriptions(id), INDEX (subscription_id, purpose), CHECK (purpose IN ('confirm','unsubscribe'))) ENGINE=InnoDB",
        ] as $statement) {
            $this->execute($statement);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Enquiry and subscription history cannot be destructively rolled back.');
    }
}

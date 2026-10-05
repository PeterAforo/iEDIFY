<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SchedulingRequestsInvitations extends AbstractMigration
{
    public function up(): void
    {
        foreach ([
            "ALTER TABLE content_items ADD COLUMN publish_at DATETIME(6) NULL AFTER status",
            "ALTER TABLE funding_rounds ADD COLUMN form_schema JSON NULL AFTER description",
            "ALTER TABLE funding_requests ADD COLUMN answers JSON NULL AFTER requested_amount, ADD COLUMN budget_media_id BIGINT UNSIGNED NULL AFTER answers, ADD FOREIGN KEY (budget_media_id) REFERENCES media_assets(id)",
            "CREATE TABLE IF NOT EXISTS data_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', note VARCHAR(1000) NULL, requested_at DATETIME(6) NOT NULL, decided_at DATETIME(6) NULL, decided_by BIGINT UNSIGNED NULL, FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (decided_by) REFERENCES users(id), INDEX (status, requested_at), CHECK (type IN ('export','deletion')), CHECK (status IN ('pending','completed','rejected'))) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS invitations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL, role_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, invited_by BIGINT UNSIGNED NULL, expires_at DATETIME(6) NOT NULL, accepted_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (role_id) REFERENCES roles(id), FOREIGN KEY (invited_by) REFERENCES users(id), INDEX (email), INDEX (expires_at)) ENGINE=InnoDB",
        ] as $statement) {
            try {
                $this->execute($statement);
            } catch (\PDOException $error) {
                // Idempotent re-runs: skip already-applied ALTER/CREATE statements.
                if (!str_contains($error->getMessage(), 'Duplicate') && !str_contains($error->getMessage(), 'exists')) {
                    throw $error;
                }
            }
        }
    }
}

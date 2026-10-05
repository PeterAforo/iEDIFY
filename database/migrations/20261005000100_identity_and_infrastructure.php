<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class IdentityAndInfrastructure extends AbstractMigration
{
    public function up(): void
    {
        $statements = [
            "CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(32) NOT NULL UNIQUE, email VARCHAR(254) NOT NULL UNIQUE, name VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', email_verified_at DATETIME(6) NULL, version INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, CHECK (status IN ('active','deactivated'))) ENGINE=InnoDB",
            'CREATE TABLE roles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, privileged BOOLEAN NOT NULL DEFAULT FALSE) ENGINE=InnoDB',
            'CREATE TABLE permissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE) ENGINE=InnoDB',
            'CREATE TABLE user_roles (user_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (user_id, role_id), FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (role_id) REFERENCES roles(id)) ENGINE=InnoDB',
            'CREATE TABLE role_permissions (role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (role_id, permission_id), FOREIGN KEY (role_id) REFERENCES roles(id), FOREIGN KEY (permission_id) REFERENCES permissions(id)) ENGINE=InnoDB',
            'CREATE TABLE auth_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, purpose VARCHAR(40) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id, purpose, expires_at)) ENGINE=InnoDB',
            'CREATE TABLE mfa_factors (user_id BIGINT UNSIGNED PRIMARY KEY, secret_ciphertext TEXT NOT NULL, confirmed_at DATETIME(6) NULL, last_used_step BIGINT NULL, FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB',
            'CREATE TABLE mfa_recovery_codes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, code_hash VARCHAR(255) NOT NULL, used_at DATETIME(6) NULL, FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id)) ENGINE=InnoDB',
            'CREATE TABLE sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, user_id BIGINT UNSIGNED NULL, payload MEDIUMBLOB NOT NULL, last_activity INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id), INDEX (expires_at)) ENGINE=InnoDB',
            'CREATE TABLE consents (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, purpose VARCHAR(80) NOT NULL, policy_version VARCHAR(80) NOT NULL, granted BOOLEAN NOT NULL, channel VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id, purpose, created_at)) ENGINE=InnoDB',
            'CREATE TABLE audit_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id VARCHAR(100) NOT NULL, metadata JSON NOT NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (actor_id) REFERENCES users(id), INDEX (entity_type, entity_id, created_at), INDEX (actor_id, created_at)) ENGINE=InnoDB',
            "CREATE TABLE outbox_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_key VARCHAR(191) NOT NULL UNIQUE, event_type VARCHAR(100) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME(6) NOT NULL, lease_token CHAR(64) NULL, lease_until DATETIME(6) NULL, last_error_code VARCHAR(80) NULL, created_at DATETIME(6) NOT NULL, processed_at DATETIME(6) NULL, INDEX (status, available_at), CHECK (status IN ('pending','processing','completed','failed','uncertain'))) ENGINE=InnoDB",
            'CREATE TABLE rate_limit_buckets (bucket_key CHAR(64) NOT NULL, window_start BIGINT NOT NULL, attempts INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, PRIMARY KEY (bucket_key, window_start), INDEX (expires_at)) ENGINE=InnoDB',
            'CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(191) NOT NULL, type VARCHAR(100) NOT NULL, title VARCHAR(180) NOT NULL, target_path VARCHAR(500) NOT NULL, read_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id), UNIQUE (user_id, event_key), INDEX (user_id, read_at, created_at)) ENGINE=InnoDB',
        ];
        foreach ($statements as $sql) {
            $this->execute($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive rollback is disabled. Use an approved restore or forward migration.');
    }
}

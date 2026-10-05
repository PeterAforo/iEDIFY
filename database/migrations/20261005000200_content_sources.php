<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ContentSources extends AbstractMigration
{
    public function up(): void
    {
        foreach ([
            "CREATE TABLE import_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(80) NOT NULL, source_checksum CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, summary JSON NOT NULL, started_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL) ENGINE=InnoDB",
            'CREATE TABLE source_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(80) NOT NULL, source_key VARCHAR(255) NOT NULL, source_url VARCHAR(1000) NOT NULL, record_type VARCHAR(40) NOT NULL, source_checksum CHAR(64) NOT NULL, raw_record JSON NOT NULL, captured_at DATE NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE (source_system, source_key, source_checksum)) ENGINE=InnoDB',
            "CREATE TABLE content_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_type VARCHAR(40) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', published_revision_id BIGINT UNSIGNED NULL, version INT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, CHECK (status IN ('draft','review','published','archived'))) ENGINE=InnoDB",
            'CREATE TABLE content_revisions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, revision_number INT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL, sections JSON NOT NULL, source_record_id BIGINT UNSIGNED NULL, author_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (content_id) REFERENCES content_items(id), FOREIGN KEY (source_record_id) REFERENCES source_records(id), FOREIGN KEY (author_id) REFERENCES users(id), UNIQUE (content_id, revision_number), UNIQUE (content_id, id)) ENGINE=InnoDB',
            'ALTER TABLE content_items ADD CONSTRAINT content_published_revision FOREIGN KEY (id, published_revision_id) REFERENCES content_revisions(content_id, id)',
            'CREATE TABLE team_members (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL UNIQUE, display_name VARCHAR(255) NOT NULL, role_label VARCHAR(255) NOT NULL, roster_group VARCHAR(30) NOT NULL, youth_adviser BOOLEAN NOT NULL DEFAULT FALSE, display_order INT UNSIGNED NOT NULL, portrait_asset_id BIGINT UNSIGNED NULL, FOREIGN KEY (content_id) REFERENCES content_items(id)) ENGINE=InnoDB',
            "CREATE TABLE media_assets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sha256 CHAR(64) NOT NULL UNIQUE, storage_path VARCHAR(500) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL, alt_text VARCHAR(500) NOT NULL DEFAULT '', classification VARCHAR(20) NOT NULL DEFAULT 'public_content', review_status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME(6) NOT NULL, CHECK (classification IN ('public_content','private'))) ENGINE=InnoDB",
            'ALTER TABLE team_members ADD FOREIGN KEY (portrait_asset_id) REFERENCES media_assets(id)',
            'CREATE TABLE source_mappings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(80) NOT NULL, source_key VARCHAR(255) NOT NULL, source_record_id BIGINT UNSIGNED NOT NULL, content_id BIGINT UNSIGNED NULL, media_id BIGINT UNSIGNED NULL, destination_path VARCHAR(500) NULL, import_run_id BIGINT UNSIGNED NOT NULL, FOREIGN KEY (source_record_id) REFERENCES source_records(id), FOREIGN KEY (content_id) REFERENCES content_items(id), FOREIGN KEY (media_id) REFERENCES media_assets(id), FOREIGN KEY (import_run_id) REFERENCES import_runs(id), UNIQUE (source_system, source_key)) ENGINE=InnoDB',
            "CREATE TABLE editorial_flags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_record_id BIGINT UNSIGNED NOT NULL, code VARCHAR(80) NOT NULL, message VARCHAR(1000) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME(6) NOT NULL, FOREIGN KEY (source_record_id) REFERENCES source_records(id), UNIQUE (source_record_id, code)) ENGINE=InnoDB",
        ] as $statement) {
            $this->execute($statement);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Content history cannot be destructively rolled back without an approved restore.');
    }
}

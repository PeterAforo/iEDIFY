<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SiteChrome extends AbstractMigration
{
    public function up(): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(80) NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME(6) NOT NULL, FOREIGN KEY (updated_by) REFERENCES users(id)) ENGINE=InnoDB',
            "CREATE TABLE IF NOT EXISTS navigation_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, menu VARCHAR(40) NOT NULL, label VARCHAR(120) NOT NULL, url VARCHAR(500) NOT NULL, sort SMALLINT UNSIGNED NOT NULL DEFAULT 0, enabled BOOLEAN NOT NULL DEFAULT TRUE, updated_at DATETIME(6) NOT NULL, INDEX (menu, enabled, sort), CHECK (menu IN ('main','footer'))) ENGINE=InnoDB",
            'CREATE TABLE IF NOT EXISTS hero_slides (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kicker VARCHAR(255) NULL, title VARCHAR(255) NOT NULL, body TEXT NULL, cta_label VARCHAR(80) NULL, cta_url VARCHAR(500) NULL, media_id BIGINT UNSIGNED NULL, sort SMALLINT UNSIGNED NOT NULL DEFAULT 0, enabled BOOLEAN NOT NULL DEFAULT TRUE, updated_at DATETIME(6) NOT NULL, FOREIGN KEY (media_id) REFERENCES media_assets(id), INDEX (enabled, sort)) ENGINE=InnoDB',
        ] as $statement) {
            $this->execute($statement);
        }
    }
}

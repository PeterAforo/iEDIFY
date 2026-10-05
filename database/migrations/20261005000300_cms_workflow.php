<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CmsWorkflow extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE content_items ADD working_state VARCHAR(20) NOT NULL DEFAULT 'draft', ADD CHECK (working_state IN ('draft','review','approved'))");
        $this->execute('CREATE TABLE publication_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, revision_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, action VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY (content_id, revision_id) REFERENCES content_revisions(content_id, id), FOREIGN KEY (actor_id) REFERENCES users(id), INDEX (content_id, created_at)) ENGINE=InnoDB');
    }

    public function down(): void
    {
        throw new RuntimeException('Publication history requires an approved forward repair or restore.');
    }
}

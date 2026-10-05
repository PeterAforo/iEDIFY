<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ScheduledBy extends AbstractMigration
{
    public function up(): void
    {
        try {
            $this->execute('ALTER TABLE content_items ADD COLUMN scheduled_by BIGINT UNSIGNED NULL AFTER publish_at, ADD FOREIGN KEY (scheduled_by) REFERENCES users(id)');
        } catch (\PDOException $error) {
            if (!str_contains($error->getMessage(), 'Duplicate') && !str_contains($error->getMessage(), 'exists')) {
                throw $error;
            }
        }
    }
}

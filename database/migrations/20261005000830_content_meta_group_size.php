<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Publication metadata (category/year) and impact small-group suppression
 * inputs (respondent count per reported group).
 */
final class ContentMetaGroupSize extends AbstractMigration
{
    public function change(): void
    {
        $this->table('content_items')
            ->addColumn('category', 'string', ['limit' => 60, 'null' => true, 'after' => 'content_type'])
            ->addColumn('pub_year', 'integer', ['signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => true, 'after' => 'category'])
            ->update();

        $this->table('impact_results')
            ->addColumn('group_size', 'integer', ['signed' => false, 'null' => true, 'after' => 'disaggregation'])
            ->update();
    }
}

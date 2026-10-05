<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Newsletter broadcast batches — each queue run audits what was sent. */
final class NewsletterBroadcasts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('newsletter_broadcasts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('body', 'text')
            ->addColumn('created_by', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('queued_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addForeignKey('created_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }
}

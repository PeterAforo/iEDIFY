<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Modules\CMS\Services\ContentPackImporter;
use PHPUnit\Framework\TestCase;

final class ContentImportTest extends TestCase
{
    public function testDryRunDoesNotMutateAndRerunDoesNotDuplicateSources(): void
    {
        $root = dirname(__DIR__, 2);
        $pdo = Connection::open(Config::load($root, true));
        $before = (int) $pdo->query('SELECT COUNT(*) FROM content_items')->fetchColumn();
        $importer = new ContentPackImporter($pdo, $root . '/storage/private/content-test');
        $report = $importer->import($root . '/iEDIFY_Website_Content_Pack', true);
        self::assertSame(14, $report['page_count']);
        self::assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM content_items')->fetchColumn());
        $importer->import($root . '/iEDIFY_Website_Content_Pack');
        $contentCount = (int) $pdo->query('SELECT COUNT(*) FROM content_items')->fetchColumn();
        $revisionCount = (int) $pdo->query('SELECT COUNT(*) FROM content_revisions')->fetchColumn();
        $sourceCount = (int) $pdo->query('SELECT COUNT(*) FROM source_records')->fetchColumn();
        $importer->import($root . '/iEDIFY_Website_Content_Pack');
        self::assertSame($contentCount, (int) $pdo->query('SELECT COUNT(*) FROM content_items')->fetchColumn());
        self::assertSame($revisionCount, (int) $pdo->query('SELECT COUNT(*) FROM content_revisions')->fetchColumn());
        self::assertSame($sourceCount, (int) $pdo->query('SELECT COUNT(*) FROM source_records')->fetchColumn());
        self::assertSame(15, (int) $pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn());
        self::assertSame(10, (int) $pdo->query("SELECT COUNT(*) FROM team_members WHERE roster_group = 'board'")->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM team_members WHERE youth_adviser = TRUE')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM content_items c JOIN source_mappings m ON m.content_id = c.id WHERE m.source_system = 'iedify-public-capture' AND c.status = 'published'")->fetchColumn());
        self::assertSame(24, (int) $pdo->query('SELECT COUNT(*) FROM media_assets')->fetchColumn());
        self::assertSame(14, (int) $pdo->query("SELECT COUNT(*) FROM source_mappings WHERE source_key LIKE 'page:%'")->fetchColumn());
    }

    public function testChangedSourcePreservesEditorialTitleAndCreatesAReviewableRevision(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root . '/iEDIFY_Website_Content_Pack';
        $temporary = $root . '/storage/private/import-fixture-' . bin2hex(random_bytes(8));
        mkdir($temporary . '/images', 0700, true);
        $files = glob($source . '/images/*');
        try {
            foreach ($files as $file) {
                copy($file, $temporary . '/images/' . basename($file));
            }
            copy($source . '/asset_manifest.json', $temporary . '/asset_manifest.json');
            $content = json_decode(file_get_contents($source . '/website_content.json'), true, 512, JSON_THROW_ON_ERROR);
            $content['pages'][0]['text'] .= "\nSynthetic source change " . bin2hex(random_bytes(8));
            file_put_contents($temporary . '/website_content.json', json_encode($content, JSON_THROW_ON_ERROR));
            $pdo = Connection::open(Config::load($root, true));
            $importer = new ContentPackImporter($pdo, $root . '/storage/private/content-test');
            $importer->import($source);
            $id = (int) $pdo->query("SELECT content_id FROM source_mappings WHERE source_key = 'page:/'")->fetchColumn();
            $pdo->prepare('UPDATE content_items SET title = ?, version = version + 1 WHERE id = ?')->execute(['Editorial title retained', $id]);
            $query = $pdo->prepare('SELECT version FROM content_items WHERE id = ?');
            $query->execute([$id]);
            $before = (int) $query->fetchColumn();
            $importer->import($temporary);
            $query = $pdo->prepare('SELECT title, version, working_state FROM content_items WHERE id = ?');
            $query->execute([$id]);
            $record = $query->fetch();
            self::assertSame('Editorial title retained', $record['title']);
            self::assertSame($before + 1, (int) $record['version']);
            self::assertSame('draft', $record['working_state']);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM content_revisions')->fetchColumn();
            $importer->import($temporary);
            self::assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM content_revisions')->fetchColumn());
        } finally {
            foreach ($files as $file) {
                $created = $temporary . '/images/' . basename($file);
                if (is_file($created)) { unlink($created); }
            }
            foreach (['asset_manifest.json', 'website_content.json'] as $file) {
                if (is_file($temporary . '/' . $file)) { unlink($temporary . '/' . $file); }
            }
            rmdir($temporary . '/images');
            rmdir($temporary);
        }
    }
}

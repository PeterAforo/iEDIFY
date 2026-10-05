<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use PDO;
use RuntimeException;

final readonly class ContentPackImporter
{
    private const SOURCE = 'iedify-public-capture';

    public function __construct(private PDO $pdo, private string $storage)
    {
    }

    public function import(string $source, bool $dryRun = false): array
    {
        $inventory = (new ContentInventory())->inspect($source);
        $content = $this->json($source . '/website_content.json');
        $manifest = $this->json($source . '/asset_manifest.json');
        $map = $this->json(dirname(__DIR__, 4) . '/database/imports/content-map.json');
        $prepared = [];
        foreach ($content['pages'] as $page) {
            $route = parse_url($page['url'], PHP_URL_PATH) ?: '/';
            if (!isset($map[$route])) {
                throw new RuntimeException('A page does not have an approved import mapping.');
            }
            $prepared[$route] = $this->pageSections($page, $map[$route]);
        }
        $inventory['dry_run'] = $dryRun;
        if ($dryRun) {
            return $inventory;
        }
        $lockName = 'iedify:content:' . $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('A content import is already running.');
        }
        try {
            if (!is_dir($this->storage) && !mkdir($this->storage, 0700, true)) {
                throw new RuntimeException('Cannot create private content storage.');
            }
            $files = $this->storeAssets($source, $inventory['assets']);
            return (new Transaction($this->pdo))->run(function () use ($source, $content, $manifest, $map, $prepared, $inventory, $files): array {
                $this->execute('INSERT INTO import_runs (source_system, source_checksum, status, summary, started_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))', [self::SOURCE, hash_file('sha256', $source . '/website_content.json'), 'running', '{}']);
                $runId = (int) $this->pdo->lastInsertId();
                $assets = [];
                foreach ($manifest as $asset) {
                    $file = $asset['local_file'];
                    $metadata = $inventory['assets'][$file];
                    $existing = $this->one('SELECT id FROM media_assets WHERE sha256 = ?', [$metadata['sha256']]);
                    if ($existing === null) {
                        $this->execute('INSERT INTO media_assets (sha256, storage_path, original_filename, mime, width, height, alt_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$metadata['sha256'], $files[$file], basename($file), $metadata['mime'], $metadata['width'], $metadata['height'], $asset['alt']]);
                        $mediaId = (int) $this->pdo->lastInsertId();
                    } else {
                        $mediaId = (int) $existing['id'];
                    }
                    $assets[$file] = $mediaId;
                    $key = 'asset:' . $file;
                    $sourceId = $this->sourceRecord($key, 'asset', $asset['page'], ['file' => $file, 'metadata' => $metadata], $content['captured_date']);
                    $this->mapping($key, $sourceId, null, $mediaId, null, $runId);
                }
                foreach ($content['pages'] as $page) {
                    $route = parse_url($page['url'], PHP_URL_PATH) ?: '/';
                    $key = 'page:' . $route;
                    $sourceId = $this->sourceRecord($key, 'page', $page['url'], $page, $content['captured_date']);
                    $this->contentRecord($key, $sourceId, $map[$route]['type'], $route, $page['title'], $prepared[$route], $runId);
                    foreach ($map[$route]['flags'] as $flag) {
                        $this->flag($sourceId, $flag);
                    }
                }
                $portraits = array_values(array_filter($manifest, static fn (array $asset): bool => str_contains($asset['page'], '/team') && !str_contains($asset['local_file'], 'logo')));
                foreach ($content['expanded_team_profiles'] as $index => $profile) {
                    $key = 'team:' . $profile['index'];
                    $sourceId = $this->sourceRecord($key, 'team', $profile['source'], $profile, $content['captured_date']);
                    $slug = '/team/' . strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $profile['name']), '-'));
                    $contentId = $this->contentRecord($key, $sourceId, 'team', $slug, $profile['name'], [['type' => 'text', 'text' => $profile['text']]], $runId);
                    $existing = $this->one('SELECT id FROM team_members WHERE content_id = ?', [$contentId]);
                    if ($existing === null) {
                        $role = in_array($profile['index'], [9, 10], true) ? 'YOUTH ADVISORY BOARD REPRESENTATIVE' : strtok($profile['text'], "\n");
                        $portrait = $portraits[$index] ?? null;
                        if ($portrait === null || $portrait['alt'] !== $profile['name']) {
                            throw new RuntimeException('Team portrait mapping requires review.');
                        }
                        $this->execute('INSERT INTO team_members (content_id, display_name, role_label, roster_group, youth_adviser, display_order, portrait_asset_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [$contentId, $profile['name'], $role, $profile['index'] <= 10 ? 'board' : 'programs', in_array($profile['index'], [9, 10], true) ? 1 : 0, $profile['index'], $assets[$portrait['local_file']]]);
                    }
                    $this->flag($sourceId, 'roster_review');
                    if (preg_match('/\b\d+\s+years? old\b/i', $profile['text'])) {
                        $this->flag($sourceId, 'dated_age');
                    }
                    if (in_array($profile['index'], [11, 12], true)) {
                        $this->flag($sourceId, 'name_spelling_review');
                    }
                }
                $summary = ['page_count' => $inventory['page_count'], 'team_count' => $inventory['team_count'], 'asset_count' => count($assets), 'missing_assets' => $inventory['missing_assets']];
                $this->execute('UPDATE import_runs SET status = ?, summary = ?, completed_at = UTC_TIMESTAMP(6) WHERE id = ?', ['completed', json_encode($summary, JSON_THROW_ON_ERROR), $runId]);
                (new AuditLog($this->pdo))->record(null, 'content.imported', 'import_run', (string) $runId);
                return $inventory + ['import_run_id' => $runId];
            });
        } finally {
            $this->execute('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function reconciliation(): array
    {
        return $this->pdo->query("SELECT m.source_key, s.source_url, m.destination_path, m.content_id, m.media_id, s.source_checksum, COALESCE(c.status, a.review_status) AS review_status, (SELECT COUNT(*) FROM editorial_flags f WHERE f.source_record_id = s.id AND f.status = 'open') AS open_flags FROM source_mappings m JOIN source_records s ON s.id = m.source_record_id LEFT JOIN content_items c ON c.id = m.content_id LEFT JOIN media_assets a ON a.id = m.media_id WHERE m.source_system = 'iedify-public-capture' ORDER BY m.source_key")->fetchAll();
    }

    private function pageSections(array $page, array $mapping): array
    {
        if ($mapping['type'] === 'auth_copy') {
            return [['type' => 'heading', 'text' => $page['title']]];
        }
        $text = $page['text'];
        if (!preg_match('/^' . preg_quote($mapping['start'], '/') . '$/m', $text, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Captured page header does not match its explicit mapping: ' . $page['url']);
        }
        $text = substr($text, $match[0][1]);
        $footer = strpos($text, "International Entrepreneurial Development and Innovation Fund for Youth in Africa—");
        if ($footer !== false) {
            $text = substr($text, 0, $footer);
        }
        $text = preg_replace('/^(Company fax|Department code)\n?/m', '', $text);
        return [['type' => 'text', 'text' => trim($text)]];
    }

    private function contentRecord(string $key, int $sourceId, string $type, string $slug, string $title, array $sections, int $runId): int
    {
        $mapping = $this->one('SELECT source_record_id, content_id FROM source_mappings WHERE source_system = ? AND source_key = ?', [self::SOURCE, $key]);
        if ($mapping !== null && (int) $mapping['source_record_id'] === $sourceId) {
            return (int) $mapping['content_id'];
        }
        if ($mapping === null) {
            $this->execute('INSERT INTO content_items (content_type, slug, title, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$type, $slug, $title]);
            $contentId = (int) $this->pdo->lastInsertId();
            $version = 1;
        } else {
            $contentId = (int) $mapping['content_id'];
            $this->one('SELECT id FROM content_items WHERE id = ? FOR UPDATE', [$contentId]);
            $row = $this->one('SELECT MAX(revision_number) AS latest FROM content_revisions WHERE content_id = ?', [$contentId]);
            $version = (int) $row['latest'] + 1;
            $this->execute("UPDATE content_items SET version = version + 1, working_state = 'draft', updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$contentId]);
            $this->flag($sourceId, 'changed_source_review');
        }
        $this->execute('INSERT INTO content_revisions (content_id, revision_number, title, sections, source_record_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$contentId, $version, $title, json_encode($sections, JSON_THROW_ON_ERROR), $sourceId]);
        $this->mapping($key, $sourceId, $contentId, null, $slug, $runId);
        return $contentId;
    }

    private function sourceRecord(string $key, string $type, string $url, array $record, string $date): int
    {
        $raw = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $raw);
        $existing = $this->one('SELECT id FROM source_records WHERE source_system = ? AND source_key = ? AND source_checksum = ?', [self::SOURCE, $key, $checksum]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $this->execute('INSERT INTO source_records (source_system, source_key, source_url, record_type, source_checksum, raw_record, captured_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [self::SOURCE, $key, $url, $type, $checksum, $raw, $date]);
        return (int) $this->pdo->lastInsertId();
    }

    private function mapping(string $key, int $sourceId, ?int $contentId, ?int $mediaId, ?string $destination, int $runId): void
    {
        $this->execute('INSERT INTO source_mappings (source_system, source_key, source_record_id, content_id, media_id, destination_path, import_run_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_record_id = ?, media_id = ?, import_run_id = ?', [self::SOURCE, $key, $sourceId, $contentId, $mediaId, $destination, $runId, $sourceId, $mediaId, $runId]);
    }

    private function flag(int $sourceId, string $code): void
    {
        $this->execute('INSERT INTO editorial_flags (source_record_id, code, message, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE code = code', [$sourceId, $code, 'Editorial review required: ' . str_replace('_', ' ', $code)]);
    }

    private function storeAssets(string $source, array $assets): array
    {
        $files = [];
        foreach ($assets as $relative => $metadata) {
            $extension = match ($metadata['mime']) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => throw new RuntimeException('Unsupported source image type.') };
            $destination = $metadata['sha256'] . '.' . $extension;
            $absolute = $this->storage . '/' . $destination;
            if (!is_file($absolute) && !copy($source . '/' . $relative, $absolute)) {
                throw new RuntimeException('Cannot preserve a source image.');
            }
            if (hash_file('sha256', $absolute) !== $metadata['sha256']) {
                throw new RuntimeException('Stored image checksum mismatch.');
            }
            $files[$relative] = $destination;
        }
        return $files;
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}

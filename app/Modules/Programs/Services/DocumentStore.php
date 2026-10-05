<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Services;

use IEdify\Core\Http\HttpError;
use PDO;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores applicant uploads in private content storage and registers them as
 * private media assets so the controlled media route can enforce ownership.
 */
final readonly class DocumentStore
{
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];
    private const MAX_BYTES = 10485760;

    public function __construct(private PDO $pdo, private string $storageDir)
    {
    }

    /**
     * @param string $classification private (owner-scoped) or public_content
     *                               (served through /media after review)
     * @param string $reviewStatus   approved for applicant uploads; public
     *                               uploads should stay pending until reviewed
     */
    public function store(UploadedFile $file, string $classification = 'private', string $reviewStatus = 'approved'): int
    {
        if (!in_array($classification, ['private', 'public_content'], true) || !in_array($reviewStatus, ['pending', 'approved'], true)) {
            throw new \InvalidArgumentException('Unsupported media classification.');
        }
        $size = $file->getSize();
        if (!$file->isValid() || $size === false || $size > self::MAX_BYTES || $size === 0) {
            throw new HttpError(422, 'Upload a file up to 10 MB.');
        }
        $mime = $file->getMimeType() ?: '';
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new HttpError(422, 'Only PDF, PNG or JPEG documents are accepted.');
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new HttpError(422, 'The upload could not be read.');
        }
        $hash = hash('sha256', $contents);
        $statement = $this->pdo->prepare('SELECT id FROM media_assets WHERE sha256 = ? AND classification = ?');
        $statement->execute([$hash, $classification]);
        $existing = $statement->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        $filename = $hash . '.' . self::ALLOWED_MIME[$mime];
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0700, true)) {
            throw new HttpError(500, 'Document storage is unavailable.');
        }
        if (file_put_contents($this->storageDir . '/' . $filename, $contents, LOCK_EX) === false) {
            throw new HttpError(500, 'The document could not be stored.');
        }
        $original = mb_substr(preg_replace('/[^\x20-\x7E]/', '_', (string) $file->getClientOriginalName()), 0, 255);
        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $size = getimagesizefromstring($contents);
            if ($size !== false) {
                $width = $size[0];
                $height = $size[1];
            }
        }
        try {
            $this->pdo->prepare("INSERT INTO media_assets (sha256, storage_path, original_filename, mime, width, height, alt_text, classification, review_status, created_at) VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, UTC_TIMESTAMP(6))")
                ->execute([$hash, $filename, $original !== '' ? $original : 'document.' . self::ALLOWED_MIME[$mime], $mime, $width, $height, $classification, $reviewStatus]);
        } catch (\PDOException $error) {
            // The same bytes may already exist under another classification.
            $statement = $this->pdo->prepare('SELECT id FROM media_assets WHERE sha256 = ?');
            $statement->execute([$hash]);
            $existing = $statement->fetchColumn();
            if ($existing === false) {
                throw $error;
            }
            return (int) $existing;
        }
        return (int) $this->pdo->lastInsertId();
    }
}

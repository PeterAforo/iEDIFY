<?php

declare(strict_types=1);

namespace IEdify\Modules\CMS\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Site chrome management: whitelisted contact/social settings, navigation
 * items and hero slides. Every read falls back to the shipped defaults so an
 * empty or missing table never breaks the public site.
 */
final readonly class SiteChromeService
{
    public const SETTING_KEYS = ['contact_email', 'contact_phone', 'contact_address', 'social_twitter', 'social_linkedin', 'social_facebook', 'social_instagram'];

    private const DEFAULT_SETTINGS = [
        'contact_email' => 'info@iedifyafrica.org',
        'contact_phone' => '+233 (0) 20 956 6403',
        'contact_address' => 'EB873 Dewberries Street, Oyibi, Greater Accra GK-0842-9404',
        'social_twitter' => 'https://twitter.com/iedifyafrica',
        'social_linkedin' => 'https://linkedin.com/company/iedify-africa',
        'social_facebook' => 'https://facebook.com/iedifyafrica',
        'social_instagram' => 'https://instagram.com/iedifyafrica',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function settings(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, value FROM site_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        return $rows + self::DEFAULT_SETTINGS;
    }

    public function updateSettings(Actor $actor, array $values): void
    {
        $this->authorize($actor);
        (new Transaction($this->pdo))->run(function () use ($actor, $values): void {
            $upsert = $this->pdo->prepare('INSERT INTO site_settings (setting_key, value, updated_by, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
            foreach ($values as $key => $value) {
                if (!in_array($key, self::SETTING_KEYS, true)) {
                    continue;
                }
                $value = mb_substr(trim((string) $value), 0, 2000);
                if (str_starts_with($key, 'social_') && $value !== '' && !(str_starts_with($value, 'https://') && strlen($value) <= 500 && filter_var($value, FILTER_VALIDATE_URL))) {
                    throw new \InvalidArgumentException('Social links must be https URLs.');
                }
                if ($key === 'contact_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Contact email must be a valid address.');
                }
                $upsert->execute([$key, $value, $actor->id]);
            }
            (new AuditLog($this->pdo))->record($actor->id, 'settings.updated', 'site_settings', 'chrome');
        });
    }

    public function navigation(string $menu): array
    {
        $statement = $this->pdo->prepare('SELECT label, url FROM navigation_items WHERE menu = ? AND enabled = 1 ORDER BY sort, id');
        $statement->execute([$menu]);
        return $statement->fetchAll(\PDO::FETCH_NUM);
    }

    public function navigationForAdmin(): array
    {
        return $this->pdo->query('SELECT * FROM navigation_items ORDER BY menu, sort, id')->fetchAll();
    }

    public function saveNavigationItem(Actor $actor, ?int $id, string $menu, string $label, string $url, int $sort, bool $enabled): void
    {
        $this->authorize($actor);
        $this->validateLink($menu, $label, $url);
        if ($sort < 0 || $sort > 999) {
            throw new \InvalidArgumentException('Sort must be 0-999.');
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $menu, $label, $url, $sort, $enabled): void {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO navigation_items (menu, label, url, sort, enabled, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')->execute([$menu, $label, $url, $sort, (int) $enabled]);
                $entityId = (string) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE navigation_items SET menu = ?, label = ?, url = ?, sort = ?, enabled = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$menu, $label, $url, $sort, (int) $enabled, $id]);
                $entityId = (string) $id;
            }
            (new AuditLog($this->pdo))->record($actor->id, 'navigation.saved', 'navigation_items', $entityId);
        });
    }

    public function deleteNavigationItem(Actor $actor, int $id): void
    {
        $this->authorize($actor);
        (new Transaction($this->pdo))->run(function () use ($actor, $id): void {
            $this->pdo->prepare('DELETE FROM navigation_items WHERE id = ?')->execute([$id]);
            (new AuditLog($this->pdo))->record($actor->id, 'navigation.deleted', 'navigation_items', (string) $id);
        });
    }

    public function heroSlides(bool $enabledOnly = true): array
    {
        return $this->pdo->query('SELECT h.*, m.width, m.height FROM hero_slides h LEFT JOIN media_assets m ON m.id = h.media_id' . ($enabledOnly ? " WHERE h.enabled = 1 AND (h.media_id IS NULL OR (m.classification = 'public_content' AND m.review_status = 'approved'))" : '') . ' ORDER BY h.sort, h.id')->fetchAll();
    }

    public function saveHeroSlide(Actor $actor, ?int $id, string $kicker, string $title, string $body, string $ctaLabel, string $ctaUrl, ?int $mediaId, int $sort, bool $enabled): void
    {
        $this->authorize($actor);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('A slide title is required.');
        }
        if ($ctaUrl !== '' && !str_starts_with($ctaUrl, '/')) {
            throw new \InvalidArgumentException('Slide call-to-action links must be site-relative.');
        }
        if ($mediaId !== null) {
            $media = $this->pdo->prepare("SELECT id FROM media_assets WHERE id = ? AND classification = 'public_content' AND review_status = 'approved'");
            $media->execute([$mediaId]);
            if ($media->fetchColumn() === false) {
                throw new \InvalidArgumentException('Slide images must be approved public media.');
            }
        }
        (new Transaction($this->pdo))->run(function () use ($actor, $id, $kicker, $title, $body, $ctaLabel, $ctaUrl, $mediaId, $sort, $enabled): void {
            $params = [mb_substr($kicker, 0, 255) ?: null, $title, $body !== '' ? mb_substr($body, 0, 4000) : null, mb_substr($ctaLabel, 0, 80) ?: null, $ctaUrl !== '' ? mb_substr($ctaUrl, 0, 500) : null, $mediaId, max(0, min(999, $sort)), (int) $enabled];
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO hero_slides (kicker, title, body, cta_label, cta_url, media_id, sort, enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')->execute($params);
                $entityId = (string) $this->pdo->lastInsertId();
            } else {
                $params[] = $id;
                $this->pdo->prepare('UPDATE hero_slides SET kicker = ?, title = ?, body = ?, cta_label = ?, cta_url = ?, media_id = ?, sort = ?, enabled = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute($params);
                $entityId = (string) $id;
            }
            (new AuditLog($this->pdo))->record($actor->id, 'hero.saved', 'hero_slides', $entityId);
        });
    }

    public function deleteHeroSlide(Actor $actor, int $id): void
    {
        $this->authorize($actor);
        (new Transaction($this->pdo))->run(function () use ($actor, $id): void {
            $this->pdo->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
            (new AuditLog($this->pdo))->record($actor->id, 'hero.deleted', 'hero_slides', (string) $id);
        });
    }

    private function validateLink(string $menu, string $label, string $url): void
    {
        if (!in_array($menu, ['main', 'footer'], true) || trim($label) === '' || mb_strlen($label) > 120) {
            throw new \InvalidArgumentException('A menu name and label are required.');
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || mb_strlen($url) > 500) {
            throw new \InvalidArgumentException('Navigation links must be site-relative paths.');
        }
    }

    private function authorize(Actor $actor): void
    {
        if (!(new Policy())->allows($actor, 'cms.edit')) {
            throw new HttpError(403, 'You do not have permission to manage site chrome.');
        }
    }
}

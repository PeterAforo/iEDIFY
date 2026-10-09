<?php

declare(strict_types=1);

namespace IEdify\Core\Http;

use IEdify\Core\Application;
use IEdify\Core\Security\Actor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    public function __construct(
        protected readonly Application $app,
        protected readonly Request $request,
        protected readonly array $vars,
    ) {
    }

    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->app->view->render($template, $data + [
            'actor' => $this->app->actor(),
            'csrf' => $this->app->csrf(),
            'chrome' => $this->chrome(),
            'flashes' => $this->app->flashes()->peekAll(),
            'current_path' => $this->request->getPathInfo(),
            'canonical_url' => rtrim($this->app->config->string('APP_URL'), '/') . $this->request->getPathInfo(),
            'environment' => $this->app->config->environment(),
        ], $status);
    }

    private function chrome(): array
    {
        $logo = '/images/logo-white.png';
        $defaultsNav = [
            ['/', 'Home'],
            ['/about', 'About'],
            ['/programs', 'Programs'],
            ['/community', 'Community'],
            ['/team', 'Team'],
            ['/impact', 'Impact'],
            ['/publications', 'Publications'],
            ['/contact', 'Contact'],
        ];
        try {
            $service = new \IEdify\Modules\CMS\Services\SiteChromeService($this->app->pdo());
            $settings = $service->settings();
            $mainNav = $service->navigation('main');
            $footerNav = $service->navigation('footer');
        } catch (\Throwable) {
            $settings = [];
            $mainNav = [];
            $footerNav = [];
        }
        $socialLabels = ['social_twitter' => 'Twitter / X', 'social_linkedin' => 'LinkedIn', 'social_facebook' => 'Facebook', 'social_instagram' => 'Instagram'];
        $social = [];
        foreach ($socialLabels as $key => $label) {
            $url = (string) ($settings[$key] ?? '');
            if ($url !== '') {
                $social[] = [$label, $url];
            }
        }
        $phone = $settings['contact_phone'] ?? '+233 (0) 20 956 6403';
        return [
            'logo_url' => $logo,
            'logo_dark_url' => '/images/logo-dark.png',
            'nav' => $mainNav !== [] ? array_map(fn (array $item): array => [$item[1], $item[0]], $mainNav) : $defaultsNav,
            'footer_nav' => array_map(fn (array $item): array => [$item[1], $item[0]], $footerNav),
            'contact' => [
                'email' => $settings['contact_email'] ?? 'info@iedifyafrica.org',
                'phone' => $phone,
                'address' => $settings['contact_address'] ?? 'EB873 Dewberries Street, Oyibi, Greater Accra GK-0842-9404',
            ],
            'social' => $social !== [] ? $social : [
                ['Twitter / X', 'https://twitter.com/iedifyafrica'],
                ['LinkedIn', 'https://linkedin.com/company/iedify-africa'],
                ['Facebook', 'https://facebook.com/iedifyafrica'],
                ['Instagram', 'https://instagram.com/iedifyafrica'],
            ],
        ];
    }

    /**
     * Load a published CMS page's sections with resolved media, for
     * controllers that render CMS-managed content outside PageController.
     */
    protected function cmsPage(string $slug): ?array
    {
        $statement = $this->app->pdo()->prepare("SELECT c.id, c.slug, c.title, c.content_type, r.sections FROM content_items c JOIN content_revisions r ON r.content_id = c.id AND r.id = c.published_revision_id WHERE c.slug = ? AND c.status = 'published'");
        $statement->execute([$slug]);
        $page = $statement->fetch();
        if ($page === false) {
            return null;
        }
        $sections = json_decode($page['sections'], true, 512, JSON_THROW_ON_ERROR);
        return ['page' => $page, 'sections' => $sections, 'media' => $this->mediaUrls($sections)];
    }

    /**
     * Resolve approved public media ids referenced by section blocks to URLs.
     */
    protected function mediaUrls(array $sections): array
    {
        $ids = [];
        $walk = function (array $blocks) use (&$walk, &$ids): void {
            foreach ($blocks as $block) {
                if (isset($block['media_id'])) {
                    $ids[] = (int) $block['media_id'];
                }
                if (isset($block['items'])) {
                    $walk($block['items']);
                }
            }
        };
        $walk($sections);
        return $this->mediaByIds($ids);
    }

    /**
     * Approved public media lookup shared by section blocks and portraits.
     *
     * @param list<int> $ids
     */
    protected function mediaByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->app->pdo()->prepare("SELECT id, width, height, mime, original_filename FROM media_assets WHERE id IN ({$placeholders}) AND classification = 'public_content' AND review_status = 'approved'");
        $statement->execute($ids);
        $media = [];
        foreach ($statement->fetchAll() as $row) {
            $media[(int) $row['id']] = [
                'url' => '/media/' . (int) $row['id'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'mime' => (string) $row['mime'],
                'filename' => (string) $row['original_filename'],
            ];
        }
        return $media;
    }

    protected function redirect(string $path): RedirectResponse
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\0")) {
            throw new HttpError(500, 'An internal redirect was rejected.');
        }
        return new RedirectResponse($path);
    }

    protected function actor(): ?Actor
    {
        return $this->app->actor();
    }

    protected function requireActor(): Actor
    {
        $actor = $this->app->actor();
        if ($actor === null) {
            $this->app->session->set(Application::SESSION_INTENDED, $this->request->getPathInfo());
            throw new HttpError(401, 'Sign in to continue.');
        }
        return $actor;
    }

    protected function input(string $key, string $default = ''): string
    {
        $value = $this->request->request->get($key);
        return is_string($value) ? trim($value) : $default;
    }

    protected function has(string $key): bool
    {
        return $this->request->request->has($key);
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->flashes()->add($type, $message);
    }

    /**
     * Consume a rate-limit bucket or reject the request.
     */
    protected function throttle(string $scope, int $limit, int $seconds): void
    {
        $subject = (string) $this->request->getClientIp();
        if (!$this->app->rateLimiter()->consume($scope, $subject, $limit, $seconds)) {
            throw new HttpError(429, 'Too many attempts. Wait a few minutes and try again.', ['Retry-After' => (string) $seconds]);
        }
    }
}

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
        $logo = null;
        try {
            $statement = $this->app->pdo()->prepare("SELECT id FROM media_assets WHERE original_filename = '03_logo-white.png' AND classification = 'public_content' AND review_status = 'approved'");
            $statement->execute();
            $id = $statement->fetchColumn();
            if ($id !== false) {
                $logo = '/media/' . (int) $id;
            }
        } catch (\Throwable) {
            $logo = null;
        }
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
        return [
            'logo_url' => $logo,
            'nav' => $mainNav !== [] ? array_map(fn (array $item): array => [$item[1], $item[0]], $mainNav) : $defaultsNav,
            'footer_nav' => array_map(fn (array $item): array => [$item[1], $item[0]], $footerNav),
            'contact' => [
                'email' => $settings['contact_email'] ?? 'info@iedifyafrica.org',
                'phone' => $settings['contact_phone'] ?? '+233 (0) 20 956 6403',
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

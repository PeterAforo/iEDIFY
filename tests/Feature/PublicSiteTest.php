<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\CMS\Services\CmsService;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PublicSiteTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root, true);
        $this->app = new Application($config, new View($root), new Session(new MockArraySessionStorage()), new NullLogger(), $root);
        $this->kernel = new Kernel($this->app);
        (new RoleSeeder($this->app->pdo()))->seed();
    }

    private function publisher(): \IEdify\Core\Security\Actor
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $userId = $identity->registerAdmin($email, 'Test Publisher', 'publisher-password-' . bin2hex(random_bytes(6)));
        self::assertNotNull($userId);
        $actor = $identity->actor($userId, true);
        self::assertNotNull($actor);
        return $actor;
    }

    public function testUnpublishedSlugReturns404AndHomeShowsPreview(): void
    {
        $missing = $this->kernel->handle(Request::create('http://127.0.0.1:8080/no-such-page-' . bin2hex(random_bytes(4))));
        self::assertSame(404, $missing->getStatusCode());
        $home = $this->kernel->handle(Request::create('http://127.0.0.1:8080/'));
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('development preview', strtolower((string) $home->getContent()));
    }

    public function testPublishedPageRendersApprovedContent(): void
    {
        $actor = $this->publisher();
        $cms = new CmsService($this->app->pdo());
        $slug = '/test-page-' . bin2hex(random_bytes(6));
        $id = $cms->create($actor, 'page', $slug, 'Feature test page', [
            ['type' => 'heading', 'text' => 'Verified heading'],
            ['type' => 'text', 'text' => 'Published body copy.'],
        ]);
        $cms->submitReview($actor, $id, 1);
        $cms->publish($actor, $id, 1, 2);

        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $slug));
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('Verified heading', $body);
        self::assertStringContainsString('Published body copy.', $body);
        self::assertStringContainsString('Feature test page', $body);
    }

    public function testUnapprovedMediaIsNotServed(): void
    {
        $statement = $this->app->pdo()->prepare("SELECT id FROM media_assets WHERE review_status = 'pending' LIMIT 1");
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            self::markTestSkipped('No pending media in the test database.');
        }
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080/media/' . (int) $id));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminRequiresMfaCompletedPrivilegedSession(): void
    {
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080/admin'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/sign-in', $response->headers->get('Location'));
    }
}

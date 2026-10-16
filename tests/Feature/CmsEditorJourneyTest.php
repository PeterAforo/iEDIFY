<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CmsEditorJourneyTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;
    private string $ip;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root, true);
        $this->app = new Application($config, new View($root), new Session(new MockArraySessionStorage()), new NullLogger(), $root);
        $this->kernel = new Kernel($this->app);
        $this->ip = '10.240.' . random_int(1, 254) . '.' . random_int(1, 254);
        (new RoleSeeder($this->app->pdo()))->seed();
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'GET', [], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    private function post(string $path, array $fields, string $csrfFrom = '/admin'): \Symfony\Component\HttpFoundation\Response
    {
        $response = $this->get($path === '/sign-in' ? '/sign-in' : $csrfFrom);
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getContent(), $match);
        self::assertNotEmpty($match[1] ?? '', 'No CSRF token available for ' . $path);
        return $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'POST', $fields + ['_csrf' => $match[1]], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    private function signInAdmin(): int
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(10)) . '@example.invalid';
        $password = 'admin-password-' . bin2hex(random_bytes(6));
        $userId = $identity->registerAdmin($email, 'CMS Admin', $password);
        self::assertNotNull($userId);
        $signIn = $this->post('/sign-in', ['email' => $email, 'password' => $password]);
        self::assertSame('/sign-in/mfa/setup', $signIn->headers->get('Location'));
        $statement = $this->app->pdo()->prepare('SELECT secret_ciphertext FROM mfa_factors WHERE user_id = ?');
        $statement->execute([$userId]);
        $secret = (new SecretBox($this->app->config->string('APP_KEY')))->decrypt((string) $statement->fetchColumn());
        $confirm = $this->post('/sign-in/mfa/setup', ['code' => TOTP::createFromSecret($secret)->now()], '/sign-in/mfa/setup');
        self::assertSame(200, $confirm->getStatusCode());
        return $userId;
    }

    public function testCreateEditPublishAndDeletePageEndToEnd(): void
    {
        $this->signInAdmin();

        $new = $this->get('/admin/content/new');
        self::assertSame(200, $new->getStatusCode());
        self::assertStringContainsString('Content type', (string) $new->getContent());

        $slug = '/smoke-' . bin2hex(random_bytes(4));
        $create = $this->post('/admin/content/new', ['type' => 'page', 'title' => 'Smoke page', 'slug' => $slug], '/admin/content/new');
        self::assertSame(302, $create->getStatusCode());
        $location = (string) $create->headers->get('Location');
        self::assertMatchesRegularExpression('~^/admin/content/\d+$~', $location);

        $edit = $this->get($location);
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('data-block-editor', (string) $edit->getContent(), 'Edit screen mounts the block editor.');
        self::assertStringContainsString('Save &amp; publish', (string) $edit->getContent());

        $id = (int) basename($location);
        $row = $this->app->pdo()->prepare('SELECT version FROM content_items WHERE id = ?');
        $row->execute([$id]);
        $version = (int) $row->fetchColumn();

        $sections = json_encode([['type' => 'heading', 'text' => 'Smoke'], ['type' => 'text', 'text' => 'Visible body text.']], JSON_THROW_ON_ERROR);
        $publish = $this->post($location . '/save-publish', ['version' => $version, 'title' => 'Smoke page', 'sections' => $sections], $location);
        self::assertSame(302, $publish->getStatusCode());
        $row = $this->app->pdo()->prepare('SELECT status FROM content_items WHERE id = ?');
        $row->execute([$id]);
        self::assertSame('published', $row->fetchColumn(), 'Save & publish must run the whole workflow.');

        $page = $this->get($slug);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Visible body text.', (string) $page->getContent());

        $row = $this->app->pdo()->prepare('SELECT version FROM content_items WHERE id = ?');
        $row->execute([$id]);
        $archive = $this->post($location . '/archive', ['version' => (int) $row->fetchColumn()], $location);
        self::assertSame(302, $archive->getStatusCode());
        $gone = $this->get($slug);
        self::assertSame(404, $gone->getStatusCode(), 'Archived pages must not be public.');

        $row = $this->app->pdo()->prepare('SELECT version FROM content_items WHERE id = ?');
        $row->execute([$id]);
        $delete = $this->post($location . '/delete', ['version' => (int) $row->fetchColumn()], $location);
        self::assertSame(302, $delete->getStatusCode());
        self::assertSame('/admin/content', $delete->headers->get('Location'));
        self::assertSame(0, (int) $this->app->pdo()->query('SELECT COUNT(*) FROM content_items WHERE id = ' . $id)->fetchColumn());
    }

    public function testTeamMemberFlow(): void
    {
        $this->signInAdmin();

        $list = $this->get('/admin/team');
        self::assertSame(200, $list->getStatusCode());

        $create = $this->post('/admin/team/new', [
            'display_name' => 'Smoke Member',
            'role_label' => 'Tester',
            'roster_group' => 'board',
            'display_order' => '50',
        ], '/admin/team/new');
        self::assertSame(302, $create->getStatusCode());
        $location = (string) $create->headers->get('Location');
        self::assertMatchesRegularExpression('~^/admin/team/\d+$~', $location);

        $edit = $this->get($location);
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('Smoke Member', (string) $edit->getContent());
        self::assertStringContainsString('data-block-editor', (string) $edit->getContent());

        $member = $this->app->pdo()->prepare('SELECT t.id, t.content_id, c.version FROM team_members t JOIN content_items c ON c.id = t.content_id WHERE t.id = ?');
        $member->execute([(int) basename($location)]);
        $member = $member->fetch();
        $save = $this->post($location, [
            'display_name' => 'Smoke Member',
            'role_label' => 'Senior Tester',
            'roster_group' => 'board',
            'display_order' => '51',
            'version' => (int) $member['version'],
            'sections' => json_encode([['type' => 'text', 'text' => 'Bio text.']], JSON_THROW_ON_ERROR),
        ], $location);
        self::assertSame(302, $save->getStatusCode());
        $check = $this->app->pdo()->prepare('SELECT role_label FROM team_members WHERE id = ?');
        $check->execute([(int) $member['id']]);
        self::assertSame('Senior Tester', $check->fetchColumn());

        $delete = $this->post($location . '/delete', [], $location);
        self::assertSame(302, $delete->getStatusCode());
        self::assertSame('/admin/team', $delete->headers->get('Location'));
    }

    public function testMediaOptionsEndpointReturnsJson(): void
    {
        $this->signInAdmin();
        $response = $this->get('/admin/media/options?kind=image');
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('items', $payload);
        self::assertIsArray($payload['items']);
    }

    public function testMediaUploadStoresAsset(): void
    {
        $this->signInAdmin();
        $page = $this->get('/admin/media');
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $page->getContent(), $match);
        self::assertNotEmpty($match[1] ?? '', 'No CSRF token on media page');

        // 1x1 transparent PNG.
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $hash = hash('sha256', $bytes);
        $png = tempnam(sys_get_temp_dir(), 'up') . '.png';
        file_put_contents($png, $bytes);
        $file = new UploadedFile($png, 'pixel.png', null, null, true);

        try {
            $response = $this->kernel->handle(Request::create(
                'http://127.0.0.1:8080/admin/media/upload',
                'POST',
                ['_csrf' => $match[1], 'alt_text' => 'test pixel'],
                [],
                ['document' => $file],
                ['REMOTE_ADDR' => $this->ip]
            ));
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/admin/media', $response->headers->get('Location'));

            $statement = $this->app->pdo()->prepare("SELECT COUNT(*) FROM media_assets WHERE sha256 = ? AND review_status = 'pending'");
            $statement->execute([$hash]);
            self::assertGreaterThan(0, (int) $statement->fetchColumn());
        } finally {
            $this->app->pdo()->prepare('DELETE FROM media_assets WHERE sha256 = ?')->execute([$hash]);
            @unlink(dirname(__DIR__, 2) . '/storage/private/content-test/' . $hash . '.png');
        }
    }
}

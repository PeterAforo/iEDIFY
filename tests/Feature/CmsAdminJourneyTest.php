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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CmsAdminJourneyTest extends TestCase
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

    public function testEditorialFlagsGatePublicationThenPageGoesLive(): void
    {
        // Use the first imported draft that carries open editorial flags.
        $item = $this->app->pdo()->query("SELECT c.id, c.slug, c.version FROM content_items c JOIN source_mappings m ON m.content_id = c.id JOIN editorial_flags f ON f.source_record_id = m.source_record_id AND f.status = 'open' WHERE c.status = 'draft' LIMIT 1")->fetch();
        if ($item === false) {
            self::markTestSkipped('No flagged imported draft remains on the test database.');
        }
        $this->signInAdmin();

        $edit = $this->get('/admin/content/' . (int) $item['id']);
        self::assertSame(200, $edit->getStatusCode());

        // Publish is blocked while flags are open.
        $statement = $this->app->pdo()->prepare('SELECT version, working_state, status FROM content_items WHERE id = ?');
        $statement->execute([$item['id']]);
        $row = $statement->fetch();
        if ($row['working_state'] === 'draft') {
            $submit = $this->post('/admin/content/' . (int) $item['id'] . '/submit-review', ['version' => (int) $row['version']]);
            self::assertSame(302, $submit->getStatusCode());
            $statement->execute([$item['id']]);
            $row = $statement->fetch();
        }
        $publish = $this->post('/admin/content/' . (int) $item['id'] . '/publish', ['version' => (int) $row['version']]);
        self::assertSame(302, $publish->getStatusCode());
        $statement->execute([$item['id']]);
        self::assertNotSame('published', $statement->fetch()['status'], 'Publication must be blocked while flags are open.');

        // Resolve flags as a reviewer and publish.
        $flags = $this->app->pdo()->query("SELECT f.id FROM editorial_flags f JOIN source_mappings m ON m.source_record_id = f.source_record_id WHERE m.content_id = " . (int) $item['id'] . " AND f.status = 'open'")->fetchAll();
        foreach ($flags as $flag) {
            $resolve = $this->post('/admin/flags/' . (int) $flag['id'] . '/resolve', []);
            self::assertSame(302, $resolve->getStatusCode());
        }
        $statement->execute([$item['id']]);
        $row = $statement->fetch();
        $publish = $this->post('/admin/content/' . (int) $item['id'] . '/publish', ['version' => (int) $row['version']]);
        self::assertSame(302, $publish->getStatusCode());
        $statement->execute([$item['id']]);
        self::assertSame('published', $statement->fetch()['status']);

        $page = $this->get((string) $item['slug']);
        self::assertSame(200, $page->getStatusCode(), 'Published page should render publicly at ' . $item['slug']);

        // Restore the review gate so repeated runs exercise the same journey.
        $this->app->pdo()->prepare("UPDATE content_items SET status = 'draft', working_state = 'draft', published_revision_id = NULL WHERE id = ?")->execute([$item['id']]);
        $this->app->pdo()->prepare("UPDATE editorial_flags f JOIN source_mappings m ON m.source_record_id = f.source_record_id SET f.status = 'open' WHERE m.content_id = ?")->execute([$item['id']]);
    }
}

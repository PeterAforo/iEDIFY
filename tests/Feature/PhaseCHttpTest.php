<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\Community\Services\EventService;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PhaseCHttpTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;
    private string $ip;

    protected function setUp(): void
    {
        $this->kernel = $this->newKernel();
        (new RoleSeeder($this->app->pdo()))->seed();
    }

    private function newKernel(): Kernel
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root, true);
        $this->app = new Application($config, new View($root), new Session(new MockArraySessionStorage()), new NullLogger(), $root);
        $this->ip = '10.242.' . random_int(1, 254) . '.' . random_int(1, 254);
        return new Kernel($this->app);
    }

    private function get(string $path): Response
    {
        return $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'GET', [], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    private function csrf(string $path): string
    {
        $response = $this->get($path);
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getContent(), $match);
        self::assertNotEmpty($match[1] ?? '', 'CSRF token not found at ' . $path);
        return $match[1];
    }

    private function post(string $path, array $fields, string $csrfFrom = '/account'): Response
    {
        return $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'POST', $fields + ['_csrf' => $this->csrf($csrfFrom)], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    private function registerParticipant(string $name = 'Member'): int
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'member-' . bin2hex(random_bytes(8));
        $userId = $identity->register($name, $email, $password, '2026-10', true);
        self::assertNotNull($userId);
        $this->app->pdo()->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$userId]);
        $signIn = $this->post('/sign-in', ['email' => $email, 'password' => $password], '/sign-in');
        self::assertSame(302, $signIn->getStatusCode());
        return $userId;
    }

    private function signInAdmin(): void
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'admin-password-' . bin2hex(random_bytes(6));
        $userId = $identity->registerAdmin($email, 'Staff Admin', $password);
        self::assertNotNull($userId);
        $signIn = $this->post('/sign-in', ['email' => $email, 'password' => $password], '/sign-in');
        self::assertSame('/sign-in/mfa/setup', $signIn->headers->get('Location'));
        $statement = $this->app->pdo()->prepare('SELECT secret_ciphertext FROM mfa_factors WHERE user_id = ?');
        $statement->execute([$userId]);
        $secret = (new SecretBox($this->app->config->string('APP_KEY')))->decrypt((string) $statement->fetchColumn());
        $setup = $this->post('/sign-in/mfa/setup', ['code' => TOTP::createFromSecret($secret)->now()], '/sign-in/mfa/setup');
        self::assertSame(200, $setup->getStatusCode());
    }

    public function testCommunityGroupPostReportAndModerationOverHttp(): void
    {
        // Member A creates a group, posts, and sees the landing→hub split.
        $ownerId = $this->registerParticipant('Owner');
        self::assertSame(200, $this->get('/community')->getStatusCode());
        $create = $this->post('/community/groups', ['slug' => 'g' . bin2hex(random_bytes(4)), 'name' => 'Founders Circle', 'description' => '', 'sector' => '', 'visibility' => 'public'], '/community');
        self::assertSame(302, $create->getStatusCode());
        $groupId = (int) $this->app->pdo()->query("SELECT id FROM community_groups ORDER BY id DESC LIMIT 1")->fetchColumn();
        $postResponse = $this->post('/community/groups/' . $groupId . '/posts', ['title' => 'First post', 'body' => 'Hello community'], '/community/groups/' . $groupId);
        self::assertSame(302, $postResponse->getStatusCode());
        $postId = (int) $this->app->pdo()->query("SELECT id FROM community_posts ORDER BY id DESC LIMIT 1")->fetchColumn();
        $detail = $this->get('/community/posts/' . $postId);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('Hello community', (string) $detail->getContent());

        // Member B joins, comments and reports the post.
        $this->kernel = $this->newKernel();
        $reporterId = $this->registerParticipant('Reporter');
        $join = $this->post('/community/groups/' . $groupId . '/join', [], '/community');
        self::assertSame(302, $join->getStatusCode());
        $report = $this->post('/community/posts/' . $postId . '/report', ['reason' => 'Test report reason'], '/community/posts/' . $postId);
        self::assertSame(302, $report->getStatusCode());
        $reportId = (int) $this->app->pdo()->query("SELECT id FROM moderation_reports ORDER BY id DESC LIMIT 1")->fetchColumn();

        // A moderator (privileged + MFA) hides the content; members then get 404.
        $this->kernel = $this->newKernel();
        $this->signInAdmin();
        self::assertSame(200, $this->get('/admin/moderation')->getStatusCode());
        $moderate = $this->post('/admin/moderation/' . $reportId, ['action' => 'hide', 'note' => ''], '/admin/moderation');
        self::assertSame(302, $moderate->getStatusCode());
        self::assertSame('hidden', $this->app->pdo()->query("SELECT status FROM community_posts WHERE id = {$postId}")->fetchColumn());
        self::assertSame('actioned', $this->app->pdo()->query("SELECT status FROM moderation_reports WHERE id = {$reportId}")->fetchColumn());

        $this->kernel = $this->newKernel();
        $this->registerParticipant('Third');
        self::assertSame(404, $this->get('/community/posts/' . $postId)->getStatusCode());
    }

    public function testPrivateGroupStaysPrivateOverHttp(): void
    {
        $this->registerParticipant('Owner');
        $create = $this->post('/community/groups', ['slug' => 'p' . bin2hex(random_bytes(4)), 'name' => 'Private Circle', 'description' => '', 'sector' => '', 'visibility' => 'private'], '/community');
        self::assertSame(302, $create->getStatusCode());
        $groupId = (int) $this->app->pdo()->query("SELECT id FROM community_groups ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->post('/community/groups/' . $groupId . '/posts', ['title' => 'Secret', 'body' => 'Members only'], '/community/groups/' . $groupId);
        $postId = (int) $this->app->pdo()->query("SELECT id FROM community_posts ORDER BY id DESC LIMIT 1")->fetchColumn();

        $this->kernel = $this->newKernel();
        $this->registerParticipant('Outsider');
        $request = $this->post('/community/groups/' . $groupId . '/join', [], '/community');
        self::assertSame(302, $request->getStatusCode());
        self::assertSame('pending', $this->app->pdo()->query("SELECT status FROM group_members WHERE group_id = {$groupId} ORDER BY id DESC LIMIT 1")->fetchColumn());
        self::assertSame(404, $this->get('/community/posts/' . $postId)->getStatusCode());
        self::assertSame(404, $this->get('/community/groups/' . $groupId)->getStatusCode());
    }

    public function testEventRegistrationAndCancellationOverHttp(): void
    {
        $staff = new Actor($this->staffUserId(), ['program.manage'], true, true, true);
        $events = new EventService($this->app->pdo());
        $slug = 'evt-' . bin2hex(random_bytes(4));
        $eventId = $events->create($staff, $slug, 'Founder Meetup', 'Meet founders.', 'Accra', '+2 days', null, 2);
        $events->setStatus($staff, $eventId, 'published', 1);

        self::assertStringContainsString('Founder Meetup', (string) $this->get('/events')->getContent());
        self::assertSame(200, $this->get('/events/' . $slug)->getStatusCode());

        $this->registerParticipant('Guest');
        self::assertSame(302, $this->post('/events/' . $eventId . '/register', [], '/events/' . $slug)->getStatusCode());
        self::assertSame('registered', $this->app->pdo()->query("SELECT status FROM event_registrations WHERE event_id = {$eventId}")->fetchColumn());
        self::assertSame(302, $this->post('/events/' . $eventId . '/cancel', [], '/events/' . $slug)->getStatusCode());
        self::assertSame('cancelled', $this->app->pdo()->query("SELECT status FROM event_registrations WHERE event_id = {$eventId}")->fetchColumn());
    }

    public function testPrivateMediaAccessIsAuthorizedOverHttp(): void
    {
        // Owner uploads evidence as a private asset referenced by a milestone.
        $ownerId = $this->registerParticipant('Owner');
        $directory = $this->app->config->string('CONTENT_STORAGE', dirname(__DIR__, 2) . '/storage/private/content');
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $contents = 'evidence-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', $contents);
        file_put_contents($directory . '/' . $hash . '.pdf', $contents);
        $this->app->pdo()->prepare("INSERT INTO media_assets (sha256, storage_path, original_filename, mime, alt_text, classification, review_status, created_at) VALUES (?, ?, 'evidence.pdf', 'application/pdf', '', 'private', 'approved', UTC_TIMESTAMP(6))")
            ->execute([$hash, $hash . '.pdf']);
        $mediaId = (int) $this->app->pdo()->lastInsertId();
        $this->app->pdo()->prepare("INSERT INTO milestones (user_id, title, due_on, evidence_media_id, created_at, updated_at) VALUES (?, 'Evidence test', '2027-01-01', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$ownerId, $mediaId]);

        // Owner can download; a stranger and a guest cannot.
        self::assertSame(200, $this->get('/media/' . $mediaId)->getStatusCode());
        $this->kernel = $this->newKernel();
        $this->registerParticipant('Stranger');
        self::assertSame(404, $this->get('/media/' . $mediaId)->getStatusCode());
        $this->kernel = $this->newKernel();
        self::assertSame(404, $this->get('/media/' . $mediaId)->getStatusCode());
    }

    public function testNotificationPreferencesGateEmailScopeOverHttp(): void
    {
        $this->registerParticipant('Prefs');
        $save = $this->post('/account/notifications/preferences', ['scope_application' => '1'], '/account/notifications');
        self::assertSame(302, $save->getStatusCode());
        $row = $this->app->pdo()->query("SELECT enabled FROM notification_preferences WHERE scope = 'community' ORDER BY user_id DESC LIMIT 1")->fetchColumn();
        self::assertSame('0', (string) $row);
        $row = $this->app->pdo()->query("SELECT enabled FROM notification_preferences WHERE scope = 'application' ORDER BY user_id DESC LIMIT 1")->fetchColumn();
        self::assertSame('1', (string) $row);
    }

    private function staffUserId(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->app->pdo()->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'Staff', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->app->pdo()->lastInsertId();
    }
}

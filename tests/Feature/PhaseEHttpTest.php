<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\Community\Services\CommunityService;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PhaseEHttpTest extends TestCase
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
        $this->ip = '10.251.' . random_int(1, 254) . '.' . random_int(1, 254);
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

    private function staffActor(array $permissions): Actor
    {
        $id = bin2hex(random_bytes(16));
        $this->app->pdo()->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'Staff', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return new Actor((int) $this->app->pdo()->lastInsertId(), $permissions, true, true, true);
    }

    private function grantRoleAndSignIn(int $userId, string $role): void
    {
        $roleId = (int) $this->app->pdo()->query('SELECT id FROM roles WHERE name = ' . $this->app->pdo()->quote($role))->fetchColumn();
        $this->app->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = user_id')->execute([$userId, $roleId]);
        $this->app->pdo()->prepare('UPDATE users SET version = version + 1 WHERE id = ?')->execute([$userId]);
        $this->kernel = $this->newKernel();
        $this->app->grantSession($userId, true);
    }

    /** Journey 11: /search returns only publicly visible records. */
    public function testSearchHonorsVisibilityOverHttp(): void
    {
        $token = 'zxq' . bin2hex(random_bytes(5));
        $this->app->pdo()->prepare("INSERT INTO events (slug, title, description, location, starts_at, status, created_at, updated_at) VALUES (?, ?, 'Body {$token}', 'Accra', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY), 'published', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['pub-' . substr($token, 0, 8), 'Open Day ' . $token]);
        $this->app->pdo()->prepare("INSERT INTO events (slug, title, description, location, starts_at, status, created_at, updated_at) VALUES (?, ?, 'Body {$token}', 'Accra', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY), 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['drf-' . substr($token, 0, 8), 'Hidden Day ' . $token]);

        $page = $this->get('/search?q=' . $token);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Open Day ' . $token, (string) $page->getContent());
        self::assertStringNotContainsString('Hidden Day ' . $token, (string) $page->getContent());
        self::assertSame(200, $this->get('/search?q=a')->getStatusCode());
    }

    /** Journey 11: the export endpoint enforces reviewer assignment and escapes formulas. */
    public function testApplicationsExportScopeAndCsvSafetyOverHttp(): void
    {
        $evilTitle = '=HYPERLINK("http://evil.invalid")';
        $this->app->pdo()->prepare("INSERT INTO programs (slug, title, summary, eligibility_summary, status, created_at, updated_at) VALUES (?, ?, 's', 'e', 'open', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute(['exp-' . bin2hex(random_bytes(4)), $evilTitle]);
        $programId = (int) $this->app->pdo()->lastInsertId();
        $this->app->pdo()->prepare("INSERT INTO intakes (program_id, name, opens_at, closes_at, eligibility_rules, status, created_at) VALUES (?, 'Export intake', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY), '{}', 'open', UTC_TIMESTAMP(6))")
            ->execute([$programId]);
        $intakeId = (int) $this->app->pdo()->lastInsertId();
        $this->app->pdo()->prepare("INSERT INTO application_forms (intake_id, version, schema_json, status, created_at) VALUES (?, 1, '{\"fields\":[]}', 'published', UTC_TIMESTAMP(6))")
            ->execute([$intakeId]);
        $formId = (int) $this->app->pdo()->lastInsertId();
        $applicantId = $this->registerParticipant('Applicant');
        $this->app->pdo()->prepare("INSERT INTO applications (public_id, intake_id, form_id, user_id, status, answers, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'submitted', '{}', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
            ->execute([bin2hex(random_bytes(16)), $intakeId, $formId, $applicantId]);
        $applicationId = (int) $this->app->pdo()->lastInsertId();

        // Guest is redirected; the signed-in applicant is forbidden.
        $this->kernel = $this->newKernel();
        self::assertSame(302, $this->get('/admin/applications/export.csv')->getStatusCode());
        $this->kernel = $this->newKernel();
        $this->app->grantSession($applicantId, false);
        self::assertSame(403, $this->get('/admin/applications/export.csv')->getStatusCode());

        // Assigned reviewer exports the row; the formula cell is escaped.
        $this->kernel = $this->newKernel();
        $reviewerA = $this->registerParticipant('Reviewer A');
        $this->grantRoleAndSignIn($reviewerA, 'reviewer');
        $this->app->pdo()->prepare('INSERT INTO review_assignments (application_id, reviewer_id, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$applicationId, $reviewerA]);
        $export = $this->get('/admin/applications/export.csv');
        self::assertSame(200, $export->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $export->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="applications.csv"', (string) $export->headers->get('Content-Disposition'));
        $body = (string) $export->getContent();
        self::assertStringNotContainsString(',' . $evilTitle . ',', $body, 'Formula-like values must be prefixed.');
        self::assertStringContainsString("'=HYPERLINK", $body, 'The escaped formula cell should be present.');

        // A reviewer without the assignment exports nothing about it.
        $this->kernel = $this->newKernel();
        $reviewerB = $this->registerParticipant('Reviewer B');
        $this->grantRoleAndSignIn($reviewerB, 'reviewer');
        $body = (string) $this->get('/admin/applications/export.csv')->getContent();
        self::assertStringNotContainsString('HYPERLINK', $body);
    }

    /** Journey 12: legacy auth redirects and SEO endpoints behave. */
    public function testLegacyRedirectsAndSeoEndpointsOverHttp(): void
    {
        $in = $this->get('/auth/sign-in');
        self::assertSame(301, $in->getStatusCode());
        self::assertSame('/sign-in', $in->headers->get('Location'));
        $up = $this->get('/auth/sign-up');
        self::assertSame(301, $up->getStatusCode());
        self::assertSame('/sign-up', $up->headers->get('Location'));

        $robots = $this->get('/robots.txt');
        self::assertSame(200, $robots->getStatusCode());
        self::assertStringContainsString('Disallow: /admin', (string) $robots->getContent());
        self::assertStringContainsString('Disallow: /partner', (string) $robots->getContent());

        $sitemap = $this->get('/sitemap.xml');
        self::assertSame(200, $sitemap->getStatusCode());
        self::assertStringContainsString('<loc>', (string) $sitemap->getContent());
        self::assertStringNotContainsString('/admin', (string) $sitemap->getContent());

        $home = $this->get('/');
        self::assertStringContainsString('rel="canonical"', (string) $home->getContent());
        self::assertStringContainsString('property="og:title"', (string) $home->getContent());
    }

    /** Opportunities board is public and its items resolve. */
    public function testOpportunitiesBoardOverHttp(): void
    {
        $staff = $this->staffActor(['program.manage']);
        $token = 'opp-' . bin2hex(random_bytes(4));
        $community = new CommunityService($this->app->pdo());
        $id = $community->createOpportunity($staff, ['title' => 'Grant call ' . $token, 'category' => 'funding', 'summary' => 'Seed grants for founders.', 'details' => 'Apply via your programme officer.', 'deadline' => '2027-06-30']);

        $board = $this->get('/opportunities');
        self::assertSame(200, $board->getStatusCode());
        self::assertStringContainsString('Grant call ' . $token, (string) $board->getContent());
        self::assertSame(200, $this->get('/opportunities/' . $id)->getStatusCode());
        self::assertSame(404, $this->get('/opportunities/999999')->getStatusCode());
    }
}

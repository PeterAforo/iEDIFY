<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\Funding\Services\FundingService;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use IEdify\Modules\Impact\Services\ImpactService;
use IEdify\Modules\Partners\Services\PartnerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PhaseDHttpTest extends TestCase
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
        $this->ip = '10.243.' . random_int(1, 254) . '.' . random_int(1, 254);
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

    private function grantRole(int $userId, string $role): void
    {
        $roleId = (int) $this->app->pdo()->query('SELECT id FROM roles WHERE name = ' . $this->app->pdo()->quote($role))->fetchColumn();
        $this->app->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = user_id')->execute([$userId, $roleId]);
        // New role must take effect in the session: bump the version to force re-resolution.
        $this->app->pdo()->prepare('UPDATE users SET version = version + 1 WHERE id = ?')->execute([$userId]);
    }

    private function staffActor(array $permissions): Actor
    {
        return new Actor($this->staffUserId(), $permissions, true, true, true);
    }

    public function testPartnerPortalIsolationAndDownloadRevocationOverHttp(): void
    {
        $partners = new PartnerService($this->app->pdo());
        $impact = new ImpactService($this->app->pdo());
        $staff = $this->staffActor(['partner.manage', 'impact.edit', 'impact.publish']);

        $orgA = $partners->createOrg($staff, 'Alpha Foundation', 'donor', null);
        $orgB = $partners->createOrg($staff, 'Beta Trust', 'donor', null);

        $officer = new Actor($this->staffUserId(), ['impact.edit'], true, true, true);
        $verifier = new Actor($this->staffUserId(), ['impact.publish'], true, true, true);
        $reportId = $impact->createReport($officer, 'Alpha brief ' . bin2hex(random_bytes(3)), '2026', 'Shared', 'Report body for partners.');
        $impact->approveReport($verifier, $reportId, 1);
        $impact->publishReport($verifier, $reportId, 2);

        // Private document the partner can download.
        $directory = $this->app->config->string('CONTENT_STORAGE', dirname(__DIR__, 2) . '/storage/private/content');
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $contents = 'shared-doc-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', $contents);
        file_put_contents($directory . '/' . $hash . '.pdf', $contents);
        $this->app->pdo()->prepare("INSERT INTO media_assets (sha256, storage_path, original_filename, mime, alt_text, classification, review_status, created_at) VALUES (?, ?, 'brief.pdf', 'application/pdf', '', 'private', 'approved', UTC_TIMESTAMP(6))")
            ->execute([$hash, $hash . '.pdf']);
        $mediaId = (int) $this->app->pdo()->lastInsertId();

        $partners->share($staff, $orgA, 'report', $reportId);
        $partners->share($staff, $orgA, 'document', $mediaId);

        // Org A member (partner_viewer role) reaches the portal and the resources.
        $memberAId = $this->registerParticipant('Member A');
        $partners->addMember($staff, $orgA, $memberAId, 'viewer');
        $this->grantRole($memberAId, 'partner_viewer');
        $this->kernel = $this->newKernel();
        // Sign in again so the session picks up the new role.
        $this->reSignIn($memberAId);
        self::assertSame(200, $this->get('/partner')->getStatusCode());
        self::assertStringContainsString('Alpha brief', (string) $this->get('/partner')->getContent());
        self::assertSame(200, $this->get('/partner/reports/' . $reportId)->getStatusCode());
        $doc = $this->get('/partner/documents/' . $mediaId);
        self::assertSame(200, $doc->getStatusCode());
        self::assertSame($contents, (string) $doc->getContent());
        self::assertSame(1, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM partner_access_log WHERE resource_id = {$mediaId} AND action = 'download'")->fetchColumn());

        // Org B member gets 404 on the same URLs — even guessing IDs.
        $this->kernel = $this->newKernel();
        $memberBId = $this->registerParticipant('Member B');
        $partners->addMember($staff, $orgB, $memberBId, 'viewer');
        $this->grantRole($memberBId, 'partner_viewer');
        $this->kernel = $this->newKernel();
        $this->reSignIn($memberBId);
        self::assertSame(404, $this->get('/partner/reports/' . $reportId)->getStatusCode());
        self::assertSame(404, $this->get('/partner/documents/' . $mediaId)->getStatusCode());

        // A user with the permission but no membership is also denied.
        $this->kernel = $this->newKernel();
        $lonerId = $this->registerParticipant('Loner');
        $this->grantRole($lonerId, 'partner_viewer');
        $this->kernel = $this->newKernel();
        $this->reSignIn($lonerId);
        self::assertSame(404, $this->get('/partner/reports/' . $reportId)->getStatusCode());

        // Revoking the document share revokes the download endpoint for org A.
        $partners->revokeShare($staff, $orgA, 'document', $mediaId);
        $this->kernel = $this->newKernel();
        $this->reSignIn($memberAId);
        self::assertSame(404, $this->get('/partner/documents/' . $mediaId)->getStatusCode());
        self::assertSame(200, $this->get('/partner/reports/' . $reportId)->getStatusCode());
    }

    public function testFundingRequestSubmitOverHttp(): void
    {
        $funding = new FundingService($this->app->pdo());
        $staff = $this->staffActor(['funding.manage']);
        $roundId = $funding->createRound($staff, 'http-' . bin2hex(random_bytes(4)), 'HTTP Round', 'Desc', 'USD', null, gmdate('Y-m-d H:i:s', strtotime('-1 hour')), gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $funding->createRuleVersion($staff, $roundId, ['impact' => 100], 5);
        $funding->setRoundStatus($staff, $roundId, 'open', 1);

        // Public round page renders the form; guests see a sign-in prompt instead.
        self::assertSame(200, $this->get('/funding/' . $roundId)->getStatusCode());
        self::assertStringContainsString('Sign in', (string) $this->get('/funding/' . $roundId)->getContent());

        $this->registerParticipant('Founder');
        self::assertStringContainsString('Submit request', (string) $this->get('/funding/' . $roundId)->getContent());
        $submit = $this->post('/funding/rounds/' . $roundId . '/requests', ['title' => 'Kiosk expansion', 'amount' => '1200.50', 'budget_summary' => 'Stock'], '/funding/' . $roundId);
        self::assertSame(302, $submit->getStatusCode());
        $row = $this->app->pdo()->query("SELECT status, requested_amount, currency FROM funding_requests ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('submitted', $row['status']);
        self::assertSame('1200.50', (string) $row['requested_amount']);
        self::assertSame('USD', $row['currency']);
        self::assertSame(200, $this->get('/account/funding')->getStatusCode());
    }

    public function testPublicImpactPageAndReport(): void
    {
        $impact = new ImpactService($this->app->pdo());
        $officer = $this->staffActor(['impact.edit', 'impact.submit']);
        $verifier = $this->staffActor(['impact.verify', 'impact.publish']);
        $code = 'HTTP_' . strtoupper(bin2hex(random_bytes(3)));
        $indicatorId = $impact->createIndicator($officer, $code, 'Businesses launched', 'Count', 'startups', 'operational', 'count', 'annual', null, null);
        $impact->setTarget($officer, $indicatorId, '2026', '40', null);
        $resultId = $impact->submitResult($officer, $indicatorId, '2026', '33', null, null, null, null, null, null);
        $impact->verify($verifier, $resultId, 1);
        $impact->publish($verifier, $resultId, 2);

        $page = $this->get('/impact');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Businesses launched', (string) $page->getContent());
        self::assertStringContainsString('33', (string) $page->getContent());
        self::assertStringContainsString('40', (string) $page->getContent());

        // Unpublished report is invisible to the public.
        $reportId = $impact->createReport($officer, 'Draft report', '2026', 's', 'b');
        self::assertSame(404, $this->get('/impact/reports/' . $reportId)->getStatusCode());
        $impact->approveReport($verifier, $reportId, 1);
        $impact->publishReport($verifier, $reportId, 2);
        self::assertSame(200, $this->get('/impact/reports/' . $reportId)->getStatusCode());
    }

    private function reSignIn(int $userId): void
    {
        // Grant the session directly so the freshly seeded role resolves.
        $this->app->grantSession($userId, false);
        self::assertNotNull($this->app->actor());
    }

    private function staffUserId(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->app->pdo()->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'Staff', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->app->pdo()->lastInsertId();
    }
}

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
use IEdify\Modules\Programs\Services\ApplicationService;
use IEdify\Modules\Programs\Services\ProgramService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ApplicationJourneyTest extends TestCase
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
        $this->ip = '10.241.' . random_int(1, 254) . '.' . random_int(1, 254);
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

    private function registerParticipant(): string
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'participant-' . bin2hex(random_bytes(8));
        $userId = $identity->register('Journey Applicant', $email, $password, '2026-10', true);
        self::assertNotNull($userId);
        $this->app->pdo()->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$userId]);
        $signIn = $this->post('/sign-in', ['email' => $email, 'password' => $password], '/sign-in');
        self::assertSame(302, $signIn->getStatusCode());
        return $email;
    }

    private function signInAdmin(): void
    {
        $identity = new IdentityService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'admin-password-' . bin2hex(random_bytes(6));
        $userId = $identity->registerAdmin($email, 'Program Admin', $password);
        self::assertNotNull($userId);
        $signIn = $this->post('/sign-in', ['email' => $email, 'password' => $password], '/sign-in');
        self::assertSame('/sign-in/mfa/setup', $signIn->headers->get('Location'));
        $statement = $this->app->pdo()->prepare('SELECT secret_ciphertext FROM mfa_factors WHERE user_id = ?');
        $statement->execute([$userId]);
        $secret = (new SecretBox($this->app->config->string('APP_KEY')))->decrypt((string) $statement->fetchColumn());
        $setup = $this->post('/sign-in/mfa/setup', ['code' => TOTP::createFromSecret($secret)->now()], '/sign-in/mfa/setup');
        self::assertSame(200, $setup->getStatusCode());
    }

    public function testFullApplyReviewEnrollJourneyOverHttp(): void
    {
        // Program, open intake and published form are created at service level.
        $programs = new ProgramService($this->app->pdo());
        $manager = new \IEdify\Core\Security\Actor($this->userId(), ['program.manage', 'application.decide', 'cohort.manage', 'application.review'], true, true, true);
        $programId = $programs->createProgram($manager, 'seed-' . bin2hex(random_bytes(5)), 'Seed Program', 'A program.', '18+');
        $programs->setProgramStatus($manager, $programId, 'open', 1);
        $intakeId = $programs->createIntake($manager, $programId, 'Intake ' . bin2hex(random_bytes(4)), '2026-01-01', '2027-01-01', 10, []);
        $programs->createForm($manager, $intakeId, [
            ['key' => 'venture', 'label' => 'Venture name', 'type' => 'text', 'required' => true],
            ['key' => 'summary', 'label' => 'Summary', 'type' => 'text', 'required' => false],
        ]);
        $programs->setIntakeStatus($manager, $intakeId, 'open', 1);
        $cohortId = $programs->createCohort($manager, $programId, 'Cohort X', null, null);

        // Applicant journey over HTTP.
        $this->kernel = $this->newKernel();
        $this->registerParticipant();
        $catalogue = $this->get('/programs');
        self::assertSame(200, $catalogue->getStatusCode());
        self::assertStringContainsString('Seed Program', (string) $catalogue->getContent());

        $apply = $this->get('/apply/' . $intakeId);
        self::assertSame(200, $apply->getStatusCode());
        $applicationId = (int) $this->app->pdo()->query("SELECT id FROM applications WHERE intake_id = {$intakeId} ORDER BY id DESC LIMIT 1")->fetchColumn();

        $save = $this->post('/apply/' . $intakeId, ['answers' => ['venture' => 'Journey venture']]);
        self::assertSame(302, $save->getStatusCode());
        $submit = $this->post('/applications/' . $applicationId . '/submit', []);
        self::assertSame(302, $submit->getStatusCode());
        self::assertSame('submitted', $this->app->pdo()->query("SELECT status FROM applications WHERE id = {$applicationId}")->fetchColumn());

        $list = $this->get('/account/applications');
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('submitted', (string) $list->getContent());

        // A second account cannot open or read the application.
        $otherKernel = $this->kernel;
        $this->kernel = $this->newKernel();
        $this->registerParticipant();
        $foreign = $this->get('/apply/' . $intakeId);
        self::assertSame(200, $foreign->getStatusCode());
        $otherId = (int) $this->app->pdo()->query("SELECT id FROM applications WHERE intake_id = {$intakeId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertNotSame($applicationId, $otherId);
        $service = new ApplicationService($this->app->pdo());
        $foreignActor = new \IEdify\Core\Security\Actor((int) $this->app->pdo()->query("SELECT user_id FROM applications WHERE id = {$otherId}")->fetchColumn(), ['application.read_own', 'application.submit'], true, false, true);
        try {
            $service->findForApplicant($foreignActor, $applicationId);
            self::fail('Another account must not read the application.');
        } catch (\IEdify\Core\Http\HttpError $error) {
            self::assertSame(404, $error->status);
        }
        $this->kernel = $otherKernel;

        // Admin journey: MFA-gated sign-in → review → decide → enroll.
        $this->kernel = $this->newKernel();
        $this->signInAdmin();
        $adminList = $this->get('/admin/applications');
        self::assertSame(200, $adminList->getStatusCode());
        $detail = $this->get('/admin/applications/' . $applicationId);
        self::assertSame(200, $detail->getStatusCode());

        foreach (['screening', 'under_review', 'accepted'] as $status) {
            $application = $this->app->pdo()->query("SELECT version FROM applications WHERE id = {$applicationId}")->fetch();
            $response = $this->post('/admin/applications/' . $applicationId . '/transition', ['status' => $status, 'note' => '']);
            self::assertSame(302, $response->getStatusCode(), 'Transition to ' . $status);
        }
        $enroll = $this->post('/admin/applications/' . $applicationId . '/enroll', ['cohort_id' => $cohortId]);
        self::assertSame(302, $enroll->getStatusCode());
        self::assertSame('enrolled', $this->app->pdo()->query("SELECT status FROM applications WHERE id = {$applicationId}")->fetchColumn());
        self::assertSame(1, (int) $this->app->pdo()->query("SELECT COUNT(*) FROM cohort_members WHERE cohort_id = {$cohortId}")->fetchColumn());
    }

    private function userId(): int
    {
        $id = bin2hex(random_bytes(16));
        $this->app->pdo()->prepare('INSERT INTO users (public_id, email, name, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$id, $id . '@example.invalid', 'Admin', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $this->app->pdo()->lastInsertId();
    }
}

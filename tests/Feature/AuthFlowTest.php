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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AuthFlowTest extends TestCase
{
    private array $app;
    private string $ip;

    private function kernel(): Kernel
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root, true);
        $session = new Session(new MockArraySessionStorage());
        $this->app = [new Application($config, new View($root), $session, new NullLogger(), $root), $session];
        $this->ip = '10.240.' . random_int(1, 254) . '.' . random_int(1, 254);
        return new Kernel($this->app[0]);
    }

    private function get(Kernel $kernel, string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'GET', [], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    private function csrf(Kernel $kernel, string $path): string
    {
        $response = $this->get($kernel, $path);
        self::assertSame(200, $response->getStatusCode(), 'GET ' . $path . ' failed');
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getContent(), $match);
        self::assertNotEmpty($match[1] ?? '', 'CSRF token not found in ' . $path);
        return $match[1];
    }

    private function post(Kernel $kernel, string $path, array $fields, string $csrf): \Symfony\Component\HttpFoundation\Response
    {
        return $kernel->handle(Request::create('http://127.0.0.1:8080' . $path, 'POST', $fields + ['_csrf' => $csrf], [], [], ['REMOTE_ADDR' => $this->ip]));
    }

    public function testRegisterVerifySignInSignOutJourney(): void
    {
        [$kernel] = [$this->kernel()];
        /** @var Application $app */
        $app = $this->app[0];
        (new RoleSeeder($app->pdo()))->seed();
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'correct-horse-' . bin2hex(random_bytes(8));

        $response = $this->post($kernel, '/sign-up', [
            'name' => 'Journey Participant',
            'email' => $email,
            'password' => $password,
            'terms_accepted' => '1',
            'age_attested' => '1',
        ], $this->csrf($kernel, '/sign-up'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/sign-up/done', $response->headers->get('Location'));

        $statement = $app->pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$email]);
        $userId = (int) $statement->fetchColumn();
        self::assertGreaterThan(0, $userId);

        $outbox = $app->pdo()->prepare("SELECT payload FROM outbox_events WHERE event_type = 'identity.verify_email' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.user_id')) = ?");
        $outbox->execute([$userId]);
        $payload = json_decode((string) $outbox->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $token = (new SecretBox($app->config->string('APP_KEY')))->decrypt($payload['token_ciphertext']);

        $verify = $kernel->handle(Request::create('http://127.0.0.1:8080/verify-email/' . $token));
        self::assertSame(200, $verify->getStatusCode());
        self::assertStringContainsString('Email verified', (string) $verify->getContent());

        $bad = $this->post($kernel, '/sign-in', ['email' => $email, 'password' => 'wrong-password-1'], $this->csrf($kernel, '/sign-in'));
        self::assertSame(422, $bad->getStatusCode());

        $ok = $this->post($kernel, '/sign-in', ['email' => $email, 'password' => $password], $this->csrf($kernel, '/sign-in'));
        self::assertSame(302, $ok->getStatusCode());
        self::assertSame('/account', $ok->headers->get('Location'));

        $account = $kernel->handle(Request::create('http://127.0.0.1:8080/account'));
        self::assertSame(200, $account->getStatusCode());
        self::assertStringContainsString('Journey Participant', (string) $account->getContent());

        $signout = $this->post($kernel, '/sign-out', [], $this->csrf($kernel, '/account'));
        self::assertSame(302, $signout->getStatusCode());
        $after = $kernel->handle(Request::create('http://127.0.0.1:8080/account'));
        self::assertSame(302, $after->getStatusCode());
        self::assertSame('/sign-in', $after->headers->get('Location'));
    }

    public function testSignupHoneypotDoesNotCreateAccount(): void
    {
        [$kernel] = [$this->kernel()];
        /** @var Application $app */
        $app = $this->app[0];
        (new RoleSeeder($app->pdo()))->seed();
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $response = $this->post($kernel, '/sign-up', [
            'name' => 'Bot',
            'email' => $email,
            'password' => 'long-enough-password',
            'terms_accepted' => '1',
            'age_attested' => '1',
            'fax' => '555-0100',
        ], $this->csrf($kernel, '/sign-up'));
        self::assertSame(302, $response->getStatusCode());
        $statement = $app->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $statement->execute([$email]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testPrivilegedSignInRequiresMfaEnrollmentThenAdminLoads(): void
    {
        [$kernel] = [$this->kernel()];
        /** @var Application $app */
        $app = $this->app[0];
        (new RoleSeeder($app->pdo()))->seed();
        $identity = new IdentityService($app->pdo(), new SecretBox($app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $password = 'admin-password-' . bin2hex(random_bytes(6));
        $userId = $identity->registerAdmin($email, 'Admin User', $password);
        self::assertNotNull($userId);

        $signIn = $this->post($kernel, '/sign-in', ['email' => $email, 'password' => $password], $this->csrf($kernel, '/sign-in'));
        self::assertSame(302, $signIn->getStatusCode());
        self::assertSame('/sign-in/mfa/setup', $signIn->headers->get('Location'));

        $setup = $kernel->handle(Request::create('http://127.0.0.1:8080/sign-in/mfa/setup'));
        self::assertSame(200, $setup->getStatusCode());
        self::assertStringContainsString('otpauth://', (string) $setup->getContent());

        $statement = $app->pdo()->prepare('SELECT secret_ciphertext FROM mfa_factors WHERE user_id = ?');
        $statement->execute([$userId]);
        $secret = (new SecretBox($app->config->string('APP_KEY')))->decrypt((string) $statement->fetchColumn());
        $code = \OTPHP\TOTP::createFromSecret($secret)->now();

        $confirm = $this->post($kernel, '/sign-in/mfa/setup', ['code' => $code], $this->csrf($kernel, '/sign-in/mfa/setup'));
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('recovery', strtolower((string) $confirm->getContent()));

        $admin = $kernel->handle(Request::create('http://127.0.0.1:8080/admin'));
        self::assertSame(200, $admin->getStatusCode());
        self::assertStringContainsString('Administration', (string) $admin->getContent());
    }

    public function testPasswordResetInvalidatesOldPasswordAndSessions(): void
    {
        [$kernel] = [$this->kernel()];
        /** @var Application $app */
        $app = $this->app[0];
        (new RoleSeeder($app->pdo()))->seed();
        $identity = new IdentityService($app->pdo(), new SecretBox($app->config->string('APP_KEY')));
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $old = 'old-password-' . bin2hex(random_bytes(6));
        $userId = $identity->register('Reset User', $email, $old, '2026-10', true);
        self::assertNotNull($userId);

        $response = $this->post($kernel, '/password/forgot', ['email' => $email], $this->csrf($kernel, '/password/forgot'));
        self::assertSame(302, $response->getStatusCode());

        $outbox = $app->pdo()->prepare("SELECT payload FROM outbox_events WHERE event_type = 'identity.password_reset' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.user_id')) = ?");
        $outbox->execute([$userId]);
        $payload = json_decode((string) $outbox->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $token = (new SecretBox($app->config->string('APP_KEY')))->decrypt($payload['token_ciphertext']);

        $new = 'new-password-' . bin2hex(random_bytes(6));
        $reset = $this->post($kernel, '/password/reset/' . $token, ['password' => $new], $this->csrf($kernel, '/password/reset/' . $token));
        self::assertSame(302, $reset->getStatusCode());
        self::assertSame('/sign-in', $reset->headers->get('Location'));

        self::assertNull($identity->authenticate($email, $old));
        self::assertNotNull($identity->authenticate($email, $new));
        // Token is single-use.
        $reuse = $this->post($kernel, '/password/reset/' . $token, ['password' => 'another-long-password'], $this->csrf($kernel, '/password/reset/' . $token));
        self::assertSame(422, $reuse->getStatusCode());
    }
}

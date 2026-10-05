<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\View\View;
use IEdify\Modules\Identity\Services\RoleSeeder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EngagementFlowTest extends TestCase
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

    private function csrf(string $path): string
    {
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080' . $path));
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getContent(), $match);
        self::assertNotEmpty($match[1] ?? '', 'CSRF token not found in ' . $path);
        return $match[1];
    }

    public function testEnquiryPersistsAndQueuesNotification(): void
    {
        $csrf = $this->csrf('/');
        $subject = 'Programme question ' . bin2hex(random_bytes(6));
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080/enquiries', 'POST', [
            '_csrf' => $csrf,
            'name' => 'Test Enquirer',
            'email' => 'enquirer@example.invalid',
            'subject' => $subject,
            'message' => 'Please tell me more about your programmes.',
            'consent' => '1',
        ]));
        self::assertSame(302, $response->getStatusCode());
        $statement = $this->app->pdo()->prepare('SELECT COUNT(*) FROM enquiries WHERE subject = ?');
        $statement->execute([$subject]);
        self::assertSame(1, (int) $statement->fetchColumn());
        $outbox = (int) $this->app->pdo()->query("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'enquiry.notify'")->fetchColumn();
        self::assertGreaterThan(0, $outbox);
    }

    public function testEnquiryHoneypotIsSilentlyDiscarded(): void
    {
        $csrf = $this->csrf('/');
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080/enquiries', 'POST', [
            '_csrf' => $csrf,
            'name' => 'Spam Bot',
            'email' => 'spam@example.invalid',
            'subject' => 'Spam',
            'message' => 'Buy things now.',
            'fax' => '555-0100',
        ]));
        self::assertSame(302, $response->getStatusCode());
        $count = (int) $this->app->pdo()->query("SELECT COUNT(*) FROM enquiries WHERE subject = 'Spam'")->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testNewsletterDoubleOptInAndUnsubscribe(): void
    {
        $email = bin2hex(random_bytes(10)) . '@example.invalid';
        $csrf = $this->csrf('/');
        $response = $this->kernel->handle(Request::create('http://127.0.0.1:8080/newsletter/subscribe', 'POST', [
            '_csrf' => $csrf,
            'email' => $email,
        ]));
        self::assertSame(302, $response->getStatusCode());

        $statement = $this->app->pdo()->prepare('SELECT id, status FROM newsletter_subscriptions WHERE email = ?');
        $statement->execute([$email]);
        $subscription = $statement->fetch();
        self::assertNotFalse($subscription);
        self::assertSame('pending', $subscription['status']);

        $outbox = $this->app->pdo()->prepare("SELECT payload FROM outbox_events WHERE event_type = 'newsletter.confirm' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.subscription_id')) = ?");
        $outbox->execute([$subscription['id']]);
        $payload = json_decode((string) $outbox->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $secrets = new SecretBox($this->app->config->string('APP_KEY'));
        $confirmToken = $secrets->decrypt($payload['confirm_ciphertext']);
        $unsubscribeToken = $secrets->decrypt($payload['unsubscribe_ciphertext']);

        $confirm = $this->kernel->handle(Request::create('http://127.0.0.1:8080/newsletter/confirm/' . $confirmToken));
        self::assertSame(200, $confirm->getStatusCode());
        $statement->execute([$email]);
        self::assertSame('subscribed', $statement->fetch()['status']);

        // Confirm token is single-use.
        $again = $this->kernel->handle(Request::create('http://127.0.0.1:8080/newsletter/confirm/' . $confirmToken));
        self::assertSame(422, $again->getStatusCode());

        $formCsrf = $this->csrf('/newsletter/unsubscribe/' . $unsubscribeToken);
        $unsubscribe = $this->kernel->handle(Request::create('http://127.0.0.1:8080/newsletter/unsubscribe/' . $unsubscribeToken, 'POST', ['_csrf' => $formCsrf]));
        self::assertSame(200, $unsubscribe->getStatusCode());
        $statement->execute([$email]);
        self::assertSame('unsubscribed', $statement->fetch()['status']);
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Security\SecretBox;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function testSignupCannotGrantRolesAndVerificationTokenIsSingleUse(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        (new RoleSeeder($pdo))->seed();
        $box = new SecretBox(base64_encode(random_bytes(32)));
        $service = new IdentityService($pdo, $box);
        $email = bin2hex(random_bytes(16)) . '@example.invalid';
        $password = bin2hex(random_bytes(20));
        $user = $service->register('New participant', $email, $password, 'policy-test-v1', true);
        self::assertNotNull($user);
        $roles = $pdo->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
        $roles->execute([$user]);
        self::assertSame(['participant'], $roles->fetchAll(\PDO::FETCH_COLUMN));
        self::assertFalse($service->authenticate($email, $password)['verified']);
        $query = $pdo->prepare("SELECT payload FROM outbox_events WHERE event_key = ?");
        $query->execute(['identity.verify.' . $user]);
        $payload = json_decode($query->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $token = $box->decrypt($payload['token_ciphertext']);
        self::assertTrue($service->verifyEmail($token));
        self::assertFalse($service->verifyEmail($token));
        self::assertTrue($service->authenticate($email, $password)['verified']);
        self::assertNull($service->authenticate($email, 'incorrect-password'));
        self::assertNull($service->register('Duplicate', $email, $password, 'policy-test-v1', true));
    }

    public function testSignupRequiresAgeAttestation(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        $service = new IdentityService($pdo, new SecretBox(base64_encode(random_bytes(32))));
        $this->expectException(\InvalidArgumentException::class);
        $service->register('Young user', 'age-test@example.invalid', bin2hex(random_bytes(20)), 'policy-test-v1', false);
    }
}

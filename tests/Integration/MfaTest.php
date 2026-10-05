<?php

declare(strict_types=1);

namespace IEdify\Tests\Integration;

use IEdify\Core\Clock\Clock;
use IEdify\Core\Config;
use IEdify\Core\Database\Connection;
use IEdify\Core\Security\SecretBox;
use IEdify\Modules\Identity\Services\IdentityService;
use IEdify\Modules\Identity\Services\MfaService;
use IEdify\Modules\Identity\Services\RoleSeeder;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

final class MfaTest extends TestCase
{
    public function testMfaSecretsAreEncryptedAndCodesCannotBeReplayed(): void
    {
        $pdo = Connection::open(Config::load(dirname(__DIR__, 2), true));
        (new RoleSeeder($pdo))->seed();
        $box = new SecretBox(base64_encode(random_bytes(32)));
        $password = bin2hex(random_bytes(20));
        $identity = new IdentityService($pdo, $box);
        $id = $identity->register('MFA test', bin2hex(random_bytes(16)) . '@example.invalid', $password, 'test', true);
        $clock = new class implements Clock {
            public int $timestamp = 1801659600;
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@' . $this->timestamp); }
        };
        $mfa = new MfaService($pdo, $box, $clock);
        $setup = $mfa->begin($id, $password);
        $query = $pdo->prepare('SELECT secret_ciphertext FROM mfa_factors WHERE user_id = ?');
        $query->execute([$id]);
        self::assertNotSame($setup['secret'], $query->fetchColumn());
        $totp = TOTP::createFromSecret($setup['secret'], $clock);
        $recovery = $mfa->confirm($id, $totp->now());
        self::assertCount(8, $recovery);
        self::assertFalse($mfa->verify($id, $totp->now()));
        $clock->timestamp += 30;
        self::assertTrue($mfa->verify($id, $totp->now()));
        self::assertFalse($mfa->verify($id, $totp->now()));
        self::assertTrue($mfa->recover($id, $recovery[0]));
        self::assertFalse($mfa->recover($id, $recovery[0]));
    }
}

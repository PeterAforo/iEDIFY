<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Core\Security\Csrf;
use IEdify\Core\Security\SecretBox;
use IEdify\Core\Security\SecurityHeaders;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FoundationSecurityTest extends TestCase
{
    public function testCsrfIsSessionBoundAndRejectsEmptyOrDifferentValues(): void
    {
        $first = new Csrf(new Session(new MockArraySessionStorage()));
        $second = new Csrf(new Session(new MockArraySessionStorage()));
        $token = $first->token();
        self::assertTrue($first->valid($token));
        self::assertFalse($first->valid(''));
        self::assertFalse($first->valid(null));
        self::assertFalse($second->valid($token));
        self::assertSame($token, $first->token());
    }

    public function testSecretsAreAuthenticatedAndUseUniqueNonces(): void
    {
        $box = new SecretBox(base64_encode(random_bytes(32)));
        $first = $box->encrypt('sensitive-value');
        self::assertNotSame($first, $box->encrypt('sensitive-value'));
        self::assertSame('sensitive-value', $box->decrypt($first));
        $this->expectException(\RuntimeException::class);
        $box->decrypt(base64_encode(random_bytes(70)));
    }

    public function testHeadersDisallowPrivateCachingAndUnsafeEval(): void
    {
        $response = new Response('private');
        (new SecurityHeaders())->apply($response, Request::create('https://example.test/portal'), 'abc123');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString("object-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-eval', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertNotNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testPermissionsDoNotImplyObjectOwnershipOrMfaCompletion(): void
    {
        $policy = new Policy();
        $actor = new Actor(7, ['application.read_own'], true, false, false);
        self::assertTrue($policy->owns($actor, 7, 'application.read_own'));
        self::assertFalse($policy->owns($actor, 8, 'application.read_own'));
        self::assertFalse($policy->allows($actor, 'cms.publish'));
        $privileged = new Actor(9, ['cms.publish'], true, true, false);
        self::assertFalse($policy->allows($privileged, 'cms.publish'));
        self::assertTrue($policy->allows(new Actor(9, ['cms.publish'], true, true, true), 'cms.publish'));
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Tests\Feature;

use IEdify\Core\Application;
use IEdify\Core\Config;
use IEdify\Core\Http\Kernel;
use IEdify\Core\View\View;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class KernelTest extends TestCase
{
    private function kernel(): Kernel
    {
        $root = dirname(__DIR__, 2);
        $config = new Config(['APP_ENV' => 'test', 'APP_URL' => 'http://localhost']);
        return new Kernel(new Application($config, new View($root), new Session(new MockArraySessionStorage()), new NullLogger(), $root));
    }

    public function testHealthCheckDoesNotExposeConfiguration(): void
    {
        $response = $this->kernel()->handle(Request::create('http://localhost/health'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testUnknownRouteReturns404AndNoIndex(): void
    {
        $response = $this->kernel()->handle(Request::create('http://localhost/not_a_route'));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testUntrustedHostIsRejected(): void
    {
        self::assertSame(400, $this->kernel()->handle(Request::create('http://attacker.test/health'))->getStatusCode());
    }

    public function testMethodNotAllowedHasAllowHeader(): void
    {
        $response = $this->kernel()->handle(Request::create('http://localhost/health', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }
}

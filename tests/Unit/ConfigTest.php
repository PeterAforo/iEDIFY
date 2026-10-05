<?php

declare(strict_types=1);

namespace IEdify\Tests\Unit;

use IEdify\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testExternalFeaturesDefaultOff(): void
    {
        $config = new Config(['APP_ENV' => 'test', 'APP_URL' => 'http://localhost']);
        self::assertFalse($config->boolean('FUNDING_ENABLED'));
        self::assertFalse($config->boolean('MAIL_LIVE_ENABLED'));
        self::assertSame('Africa/Accra', $config->string('APP_TIMEZONE', 'Africa/Accra'));
    }

    public function testInvalidBooleanFailsRatherThanActivatingFeature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Config(['SMS_ENABLED' => 'perhaps']))->boolean('SMS_ENABLED');
    }

    public function testProductionRequiresHttpsAndSecureCookies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Config(['APP_ENV' => 'production', 'APP_URL' => 'http://example.test']))->validate();
    }

    public function testProductionRequiresValidEncryptionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Config(['APP_ENV' => 'production', 'APP_URL' => 'https://example.test', 'SESSION_SECURE' => 'true']))->validate();
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core;

use Dotenv\Dotenv;
use InvalidArgumentException;

final readonly class Config
{
    public function __construct(private array $values)
    {
    }

    public static function load(string $root, bool $test = false): self
    {
        if ($test) {
            $path = $root . '/.env.test';
            if (!is_file($path)) {
                throw new InvalidArgumentException('A dedicated .env.test file is required.');
            }
            $values = Dotenv::parse((string) file_get_contents($path));
            if (($values['APP_ENV'] ?? '') !== 'test' || !preg_match('/_test$/D', $values['DB_DATABASE'] ?? '') || ($values['MAIL_LIVE_ENABLED'] ?? '') !== 'false') {
                throw new InvalidArgumentException('Test environment isolation checks failed.');
            }
            return new self($values);
        }
        Dotenv::createImmutable($root)->safeLoad();
        return new self(array_replace($_SERVER, $_ENV));
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Invalid configuration value: {$key}");
        }
        return (string) $value;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;
        $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($result === null) {
            throw new InvalidArgumentException("Invalid boolean configuration: {$key}");
        }
        return $result;
    }

    public function integer(string $key, int $default, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        $value = filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Invalid integer configuration: {$key}");
        }
        return $value;
    }

    public function environment(): string
    {
        return $this->string('APP_ENV', 'production');
    }

    public function validate(): void
    {
        if (!in_array($this->environment(), ['local', 'test', 'staging', 'production'], true)) {
            throw new InvalidArgumentException('Unknown application environment.');
        }
        $url = $this->string('APP_URL');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null) {
            throw new InvalidArgumentException('APP_URL must be an absolute HTTP(S) origin without credentials or query.');
        }
        if (in_array($this->environment(), ['staging', 'production'], true)) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$this->boolean('SESSION_SECURE')) {
                throw new InvalidArgumentException('Staging and production require HTTPS and secure cookies.');
            }
        }
        $key = base64_decode($this->string('APP_KEY'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('APP_KEY must contain a base64-encoded 32-byte random key.');
        }
        foreach (['FUNDING_ENABLED', 'SMS_ENABLED', 'SIGNUP_ENABLED', 'MAIL_LIVE_ENABLED'] as $flag) {
            $this->boolean($flag);
        }
        if ($this->environment() !== 'production' && $this->boolean('MAIL_LIVE_ENABLED')) {
            throw new InvalidArgumentException('Live mail is not enabled for non-production environments.');
        }
    }
}

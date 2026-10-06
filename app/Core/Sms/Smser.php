<?php

declare(strict_types=1);

namespace IEdify\Core\Sms;

use IEdify\Core\Config;
use InvalidArgumentException;

/**
 * SMS facade mirroring Mailer: validates the recipient, picks the configured
 * transport (capture locally; mNotify when approved) and leaves delivery
 * failures to the outbox retry path.
 */
final readonly class Smser
{
    public function __construct(private Config $config, private string $root)
    {
    }

    public function send(string $to, string $message): void
    {
        $to = preg_replace('/[^0-9+]/', '', $to) ?? '';
        if (!preg_match('/^\+?[0-9]{7,15}$/', $to)) {
            throw new InvalidArgumentException('Invalid SMS recipient number.');
        }
        if (mb_strlen($message) > 800) {
            throw new InvalidArgumentException('SMS messages are limited to 800 characters.');
        }
        $this->transport()->send($to, $message);
    }

    private function transport(): SmsTransport
    {
        return match ($this->config->string('SMS_TRANSPORT', 'capture')) {
            'capture' => new CaptureSmsTransport($this->config->path('SMS_CAPTURE_DIR', $this->root . '/storage/mail/sms')),
            'mnotify' => new MNotifyTransport($this->config),
            default => throw new InvalidArgumentException('Unknown SMS transport.'),
        };
    }
}

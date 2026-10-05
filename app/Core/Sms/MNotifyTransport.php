<?php

declare(strict_types=1);

namespace IEdify\Core\Sms;

use IEdify\Core\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * mNotify (Ghana SMS aggregator) adapter. Disabled by configuration until
 * credentials and an explicit approval exist — `send` fails closed rather
 * than silently dropping messages.
 */
final readonly class MNotifyTransport implements SmsTransport
{
    public function __construct(private Config $config)
    {
    }

    public function send(string $to, string $message): void
    {
        if ($this->config->string('SMS_ENABLED', 'false') !== 'true') {
            throw new RuntimeException('SMS delivery is not approved; the event will retry once SMS_ENABLED=true.');
        }
        $endpoint = $this->config->string('SMS_MNOTIFY_ENDPOINT', '');
        $key = $this->config->string('SMS_MNOTIFY_KEY', '');
        $sender = $this->config->string('SMS_MNOTIFY_SENDER', '');
        if ($endpoint === '' || $key === '' || $sender === '') {
            throw new RuntimeException('SMS delivery is not configured; the event will retry once credentials and approval exist.');
        }
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new RuntimeException('Could not initialise the SMS request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['recipient' => [$to], 'sender' => $sender, 'message' => $message], JSON_THROW_ON_ERROR),
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('SMS provider rejected the delivery (HTTP ' . $status . ').');
        }
    }
}

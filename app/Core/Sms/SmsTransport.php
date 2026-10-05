<?php

declare(strict_types=1);

namespace IEdify\Core\Sms;

interface SmsTransport
{
    /**
     * Deliver an SMS. Implementations throw on provider/network failure so
     * the outbox retry/backoff path can schedule a later attempt.
     */
    public function send(string $to, string $message): void;
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Mail;

interface Transport
{
    public function send(string $to, string $subject, string $html, string $text): void;
}

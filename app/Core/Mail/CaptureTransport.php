<?php

declare(strict_types=1);

namespace IEdify\Core\Mail;

use RuntimeException;

/**
 * Local/test transport: persists each message as an .eml-style file so
 * journeys can be verified without any external service.
 */
final readonly class CaptureTransport implements Transport
{
    public function __construct(private string $directory)
    {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Cannot create the mail capture directory.');
        }
        $file = $this->directory . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8)) . '.eml';
        $message = "To: {$to}\nSubject: {$subject}\nContent-Type: text/plain; charset=utf-8\n\n{$text}\n\n--- html ---\n{$html}\n";
        if (file_put_contents($file, $message, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the captured message.');
        }
    }
}

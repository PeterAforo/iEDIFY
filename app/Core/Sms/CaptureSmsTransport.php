<?php

declare(strict_types=1);

namespace IEdify\Core\Sms;

use RuntimeException;

/** Local/test transport: persists each SMS as a file, mirroring the mail capture. */
final readonly class CaptureSmsTransport implements SmsTransport
{
    public function __construct(private string $directory)
    {
    }

    public function send(string $to, string $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Cannot create the SMS capture directory.');
        }
        $file = $this->directory . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8)) . '.sms';
        if (file_put_contents($file, "To: {$to}\n\n{$message}\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write the captured SMS.');
        }
    }
}

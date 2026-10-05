<?php

declare(strict_types=1);

namespace IEdify\Core\Mail;

use IEdify\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final readonly class SmtpTransport implements Transport
{
    public function __construct(private Config $config)
    {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (!$this->config->boolean('MAIL_LIVE_ENABLED')) {
            throw new RuntimeException('Live mail is disabled for this environment.');
        }
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $this->config->string('SMTP_HOST');
        $mailer->Port = $this->config->integer('SMTP_PORT', 587, 1, 65535);
        $mailer->SMTPSecure = $this->config->string('SMTP_ENCRYPTION', 'tls');
        $username = $this->config->string('SMTP_USERNAME');
        if ($username !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $this->config->string('SMTP_PASSWORD');
        }
        $mailer->setFrom($this->config->string('MAIL_FROM_ADDRESS'), $this->config->string('MAIL_FROM_NAME', 'iEDIFY Africa'));
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = $text;
        $mailer->send();
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Mail;

use IEdify\Core\Config;
use InvalidArgumentException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class Mailer
{
    private Environment $twig;

    public function __construct(private Config $config, private string $root)
    {
        $this->twig = new Environment(new FilesystemLoader($root . '/resources/views/mail'), ['autoescape' => 'html', 'strict_variables' => true]);
    }

    public function send(string $to, string $subject, string $template, array $data = []): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient address.');
        }
        $html = $this->twig->render($template . '.twig', $data + ['app_url' => rtrim($this->config->string('APP_URL'), '/')]);
        $text = trim(preg_replace('/\s+/', ' ', (string) strip_tags($html)));
        $this->transport()->send($to, $subject, $html, $text);
    }

    private function transport(): Transport
    {
        return match ($this->config->string('MAIL_TRANSPORT', 'capture')) {
            'capture' => new CaptureTransport($this->config->path('MAIL_CAPTURE_DIR', $this->root . '/storage/mail')),
            'smtp' => new SmtpTransport($this->config),
            default => throw new InvalidArgumentException('Unknown mail transport.'),
        };
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Core\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class Csrf
{
    public function __construct(private SessionInterface $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function valid(?string $submitted): bool
    {
        $stored = $this->session->get('_csrf');
        return is_string($stored) && is_string($submitted) && strlen($submitted) === 64 && hash_equals($stored, $submitted);
    }
}

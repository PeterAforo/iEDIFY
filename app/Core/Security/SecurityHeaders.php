<?php

declare(strict_types=1);

namespace IEdify\Core\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function apply(Response $response, Request $request, string $requestId): Response
    {
        // Signed draft previews render inside the admin's split-pane iframe,
        // so they alone may be framed by the same origin. Everything else
        // stays clickjacking-proof.
        $isPreview = preg_match('#^/preview/\d+/[a-f0-9]{64}$#', $request->getPathInfo()) === 1;
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $isPreview ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'self' https://www.openstreetmap.org; base-uri 'self'; form-action 'self'; frame-ancestors " . ($isPreview ? "'self'" : "'none'"));
        // Symfony's ResponseHeaderBag pre-seeds 'no-cache, private'; only an
        // explicitly different value (e.g. immutable media) is respected.
        if ($response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}

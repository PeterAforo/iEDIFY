<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

final class VerifyEmailController extends Controller
{
    public function submit(): Response
    {
        $verified = $this->app->identity()->verifyEmail((string) $this->vars['token']);
        return $this->render('identity/verify-email.twig', ['verified' => $verified], $verified ? 200 : 422);
    }
}

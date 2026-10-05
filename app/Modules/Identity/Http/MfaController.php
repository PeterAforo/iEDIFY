<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Application;
use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second-factor challenge for privileged accounts between password
 * verification and a full session grant.
 */
final class MfaController extends Controller
{
    public function challengeForm(): Response
    {
        $userId = $this->pending();
        if (!$this->app->mfa()->enabled($userId)) {
            return $this->redirect('/sign-in/mfa/setup');
        }
        return $this->render('identity/mfa-challenge.twig');
    }

    public function challenge(): Response
    {
        $this->throttle('auth.mfa', 10, 600);
        $userId = $this->pending();
        if (!$this->app->mfa()->enabled($userId)) {
            return $this->redirect('/sign-in/mfa/setup');
        }
        if (!$this->app->mfa()->verify($userId, $this->input('code'))) {
            $this->flash('error', 'The authentication code is incorrect or has already been used.');
            return $this->render('identity/mfa-challenge.twig', [], 422);
        }
        return $this->promote($userId);
    }

    public function recover(): Response
    {
        $this->throttle('auth.mfa', 10, 600);
        $userId = $this->pending();
        if (!$this->app->mfa()->recover($userId, $this->input('recovery_code'))) {
            $this->flash('error', 'The recovery code is incorrect or has already been used.');
            return $this->render('identity/mfa-challenge.twig', ['recovery' => true], 422);
        }
        return $this->promote($userId);
    }

    public function setupForm(): Response
    {
        $userId = $this->pending();
        $uri = $this->app->mfa()->pendingSetup($userId);
        if ($uri === null) {
            throw new HttpError(403, 'Multi-factor enrollment was not started. Sign in again.');
        }
        return $this->render('identity/mfa-setup.twig', ['uri' => $uri]);
    }

    public function setupSubmit(): Response
    {
        $this->throttle('auth.mfa', 10, 600);
        $userId = $this->pending();
        try {
            $codes = $this->app->mfa()->confirm($userId, $this->input('code'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            $uri = $this->app->mfa()->pendingSetup($userId);
            if ($uri === null) {
                throw $error;
            }
            return $this->render('identity/mfa-setup.twig', ['uri' => $uri], 422);
        }
        $this->app->grantSession($userId, true);
        return $this->render('identity/mfa-recovery-codes.twig', ['codes' => $codes]);
    }

    private function pending(): int
    {
        $userId = $this->app->session->get(Application::SESSION_PENDING_MFA);
        if (!is_int($userId)) {
            throw new HttpError(403, 'Sign in before completing multi-factor authentication.');
        }
        return $userId;
    }

    private function promote(int $userId): Response
    {
        $this->app->grantSession($userId, true);
        $path = $this->app->session->get(Application::SESSION_INTENDED, '/account');
        $this->app->session->remove(Application::SESSION_INTENDED);
        return $this->redirect(is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/account');
    }
}

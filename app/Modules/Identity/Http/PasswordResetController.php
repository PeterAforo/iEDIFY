<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Http\Controller;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class PasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return $this->render('identity/password-forgot.twig');
    }

    public function request(): Response
    {
        $this->throttle('auth.password_forgot', 5, 3600);
        $this->app->identity()->requestPasswordReset($this->input('email'));
        $this->flash('success', 'If an account exists for this email, a reset link has been sent.');
        return $this->redirect('/sign-in');
    }

    public function form(): Response
    {
        return $this->render('identity/password-reset.twig', ['token' => $this->vars['token']]);
    }

    public function submit(): Response
    {
        $this->throttle('auth.password_reset', 10, 600);
        $password = (string) $this->request->request->get('password');
        $token = (string) $this->vars['token'];
        try {
            $reset = $this->app->identity()->resetPassword($token, $password);
        } catch (InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->render('identity/password-reset.twig', ['token' => $token], 422);
        }
        if (!$reset) {
            $this->flash('error', 'This reset link is invalid or has expired. Request a new one.');
            return $this->render('identity/password-reset.twig', ['token' => $token], 422);
        }
        $this->flash('success', 'Your password was updated. Sign in with the new password.');
        return $this->redirect('/sign-in');
    }
}

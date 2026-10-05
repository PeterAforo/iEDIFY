<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Application;
use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

final class SignInController extends Controller
{
    public function form(): Response
    {
        return $this->render('identity/sign-in.twig');
    }

    public function submit(): Response
    {
        $this->throttle('auth.login', 10, 600);
        $email = $this->input('email');
        $password = (string) $this->request->request->get('password');
        $result = $this->app->identity()->authenticate($email, $password);
        if ($result === null) {
            (new Transaction($this->app->pdo()))->run(function (): void {
                (new AuditLog($this->app->pdo()))->record(null, 'identity.sign_in_failed', 'session', 'anonymous');
            });
            $this->flash('error', 'The email or password is incorrect.');
            return $this->render('identity/sign-in.twig', ['email' => $email], 422);
        }
        $actor = $this->app->identity()->actor($result['id']);
        if ($actor === null) {
            $this->flash('error', 'This account is not available. Contact iEDIFY Africa for help.');
            return $this->render('identity/sign-in.twig', ['email' => $email], 422);
        }
        if ($actor->privileged) {
            $this->app->session->migrate(true);
            $this->app->session->set(Application::SESSION_PENDING_MFA, $result['id']);
            if (!$this->app->mfa()->enabled($result['id'])) {
                $this->app->mfa()->begin($result['id'], $password);
                return $this->redirect('/sign-in/mfa/setup');
            }
            return $this->redirect('/sign-in/mfa');
        }
        $this->app->grantSession($result['id'], true);
        (new Transaction($this->app->pdo()))->run(function () use ($result): void {
            (new AuditLog($this->app->pdo()))->record($result['id'], 'identity.signed_in', 'user', (string) $result['id']);
        });
        return $this->redirect($this->intended());
    }

    private function intended(): string
    {
        $path = $this->app->session->get(Application::SESSION_INTENDED, '/account');
        $this->app->session->remove(Application::SESSION_INTENDED);
        return is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/account';
    }
}

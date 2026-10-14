<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http\Admin;

use IEdify\Core\Application;
use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dedicated authentication entry point for the administration area. The CMS
 * shares the site's user accounts and MFA pipeline, but presents its own
 * AdminLTE-branded login and requires a privileged actor.
 */
final class AdminAuthController extends Controller
{
    public function form(): Response
    {
        $actor = $this->app->actor();
        if ($actor !== null && $actor->privileged && $actor->mfaComplete) {
            return $this->redirect('/admin');
        }
        return $this->render('admin/login.twig');
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
            return $this->render('admin/login.twig', ['email' => $email], 422);
        }
        $actor = $this->app->identity()->actor($result['id']);
        if ($actor === null || !$actor->privileged) {
            $this->flash('error', 'This account does not have administrative access.');
            return $this->render('admin/login.twig', ['email' => $email], 422);
        }
        $this->app->session->migrate(true);
        $this->app->session->set(Application::SESSION_PENDING_MFA, $result['id']);
        $this->app->session->set(Application::SESSION_ADMIN_FLOW, true);
        $intended = $this->app->session->get(Application::SESSION_INTENDED);
        if (!is_string($intended) || !str_starts_with($intended, '/admin')) {
            $this->app->session->set(Application::SESSION_INTENDED, '/admin');
        }
        if (!$this->app->mfa()->enabled($result['id'])) {
            $this->app->mfa()->begin($result['id'], $password);
            return $this->redirect('/sign-in/mfa/setup');
        }
        return $this->redirect('/sign-in/mfa');
    }

    public function logout(): Response
    {
        $this->app->clearAuthentication();
        $this->app->session->invalidate();
        return $this->redirect('/admin/login');
    }
}

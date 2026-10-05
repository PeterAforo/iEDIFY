<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class AccountController extends Controller
{
    public function index(): Response
    {
        $actor = $this->requireActor();
        $statement = $this->app->pdo()->prepare('SELECT name, email, created_at FROM users WHERE id = ?');
        $statement->execute([$actor->id]);
        $user = $statement->fetch();
        return $this->render('account/index.twig', ['user' => $user === false ? null : $user]);
    }

    public function resendVerification(): Response
    {
        $actor = $this->requireActor();
        $this->throttle('auth.resend_verification', 3, 3600);
        if ($this->app->identity()->resendVerification($actor->id)) {
            $this->flash('success', 'A new verification link is on its way to your inbox.');
        } else {
            $this->flash('info', 'Your email address is already verified.');
        }
        return $this->redirect('/account');
    }

    public function signOut(): Response
    {
        $this->app->clearAuthentication();
        $this->app->session->invalidate();
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/');
    }

    public function notifications(): Response
    {
        $actor = $this->requireActor();
        $statement = $this->app->pdo()->prepare('SELECT id, type, title, target_path, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $statement->execute([$actor->id]);
        return $this->render('account/notifications.twig', ['notifications' => $statement->fetchAll()]);
    }

    public function markNotificationRead(): Response
    {
        $actor = $this->requireActor();
        $id = (int) $this->request->request->get('id');
        $this->app->pdo()->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([$id, $actor->id]);
        return $this->redirect('/account/notifications');
    }

    public function security(): Response
    {
        $actor = $this->requireActor();
        return $this->render('account/security.twig', [
            'mfa_enabled' => $this->app->mfa()->enabled($actor->id),
        ]);
    }

    public function mfaBegin(): Response
    {
        $actor = $this->requireActor();
        try {
            $setup = $this->app->mfa()->begin($actor->id, (string) $this->request->request->get('password'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            return $this->render('account/security.twig', ['mfa_enabled' => $this->app->mfa()->enabled($actor->id)], $error->status);
        }
        return $this->render('account/mfa-enable.twig', ['uri' => $setup['uri']]);
    }

    public function mfaConfirm(): Response
    {
        $actor = $this->requireActor();
        try {
            $codes = $this->app->mfa()->confirm($actor->id, $this->input('code'));
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
            $uri = $this->app->mfa()->pendingSetup($actor->id);
            return $this->render('account/mfa-enable.twig', ['uri' => $uri], $error->status);
        }
        return $this->render('identity/mfa-recovery-codes.twig', ['codes' => $codes]);
    }
}

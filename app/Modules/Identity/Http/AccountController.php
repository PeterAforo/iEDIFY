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

    public function dataRequest(): Response
    {
        $this->throttle('account.data_request', 3, 86400);
        try {
            (new \IEdify\Modules\Identity\Services\DataRequestService($this->app->pdo()))->request($this->requireActor(), $this->input('type'));
            $this->flash('success', 'Your request has been recorded. The team will follow up by email.');
        } catch (\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
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

    private const NOTIFICATION_SCOPES = ['application', 'learning', 'mentoring', 'milestone', 'event', 'community', 'general'];

    public function notifications(): Response
    {
        $actor = $this->requireActor();
        $statement = $this->app->pdo()->prepare('SELECT id, type, title, target_path, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $statement->execute([$actor->id]);
        $preferences = $this->app->pdo()->prepare('SELECT scope, enabled FROM notification_preferences WHERE user_id = ?');
        $preferences->execute([$actor->id]);
        $consents = $this->app->pdo()->prepare('SELECT purpose, granted FROM consents c WHERE user_id = ? AND id = (SELECT MAX(id) FROM consents WHERE user_id = c.user_id AND purpose = c.purpose)');
        $consents->execute([$actor->id]);
        return $this->render('account/notifications.twig', [
            'notifications' => $statement->fetchAll(),
            'scopes' => self::NOTIFICATION_SCOPES,
            'preferences' => array_column($preferences->fetchAll(), 'enabled', 'scope'),
            'consents' => array_column($consents->fetchAll(), 'granted', 'purpose'),
            'policy_version' => $this->app->config->string('POLICY_VERSION', '2026-10'),
        ]);
    }

    public function saveNotificationPreferences(): Response
    {
        $actor = $this->requireActor();
        $pdo = $this->app->pdo();
        (new \IEdify\Core\Database\Transaction($pdo))->run(function () use ($actor, $pdo): void {
            $statement = $pdo->prepare('INSERT INTO notification_preferences (user_id, scope, enabled, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = UTC_TIMESTAMP(6)');
            foreach (self::NOTIFICATION_SCOPES as $scope) {
                $statement->execute([$actor->id, $scope, $this->has('scope_' . $scope) ? 1 : 0]);
            }
            // Consent decisions are an append-only record — a new row per change.
            $consent = $pdo->prepare('INSERT INTO consents (user_id, purpose, policy_version, granted, channel, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
            $policyVersion = $this->app->config->string('POLICY_VERSION', '2026-10');
            $consent->execute([$actor->id, 'sms_notifications', $policyVersion, $this->has('consent_sms') ? 1 : 0, 'web']);
            $consent->execute([$actor->id, 'marketing_email', $policyVersion, $this->has('consent_marketing') ? 1 : 0, 'web']);
            (new \IEdify\Core\Audit\AuditLog($pdo))->record($actor->id, 'account.preferences_saved', 'users', (string) $actor->id);
        });
        $this->flash('success', 'Notification preferences saved.');
        return $this->redirect('/account/notifications');
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

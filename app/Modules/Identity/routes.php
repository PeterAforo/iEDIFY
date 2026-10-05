<?php

declare(strict_types=1);

use IEdify\Modules\Identity\Http\AccountController;
use IEdify\Modules\Identity\Http\MfaController;
use IEdify\Modules\Identity\Http\PasswordResetController;
use IEdify\Modules\Identity\Http\RegisterController;
use IEdify\Modules\Identity\Http\SignInController;
use IEdify\Modules\Identity\Http\VerifyEmailController;

return [
    ['GET', '/sign-in', [SignInController::class, 'form'], ['guest' => true]],
    ['POST', '/sign-in', [SignInController::class, 'submit'], ['guest' => true]],
    ['GET', '/sign-in/mfa', [MfaController::class, 'challengeForm']],
    ['POST', '/sign-in/mfa', [MfaController::class, 'challenge']],
    ['GET', '/sign-in/mfa/setup', [MfaController::class, 'setupForm']],
    ['POST', '/sign-in/mfa/setup', [MfaController::class, 'setupSubmit']],
    ['POST', '/sign-in/mfa/recovery', [MfaController::class, 'recover']],
    ['GET', '/sign-up', [RegisterController::class, 'form'], ['guest' => true]],
    ['POST', '/sign-up', [RegisterController::class, 'submit'], ['guest' => true]],
    ['GET', '/sign-up/done', [RegisterController::class, 'done'], ['guest' => true]],
    ['GET', '/verify-email/{token:[a-f0-9]{64}}', [VerifyEmailController::class, 'submit']],
    ['GET', '/password/forgot', [PasswordResetController::class, 'requestForm'], ['guest' => true]],
    ['POST', '/password/forgot', [PasswordResetController::class, 'request'], ['guest' => true]],
    ['GET', '/password/reset/{token:[a-f0-9]{64}}', [PasswordResetController::class, 'form'], ['guest' => true]],
    ['POST', '/password/reset/{token:[a-f0-9]{64}}', [PasswordResetController::class, 'submit'], ['guest' => true]],
    ['POST', '/sign-out', [AccountController::class, 'signOut'], ['auth' => true]],
    ['GET', '/account', [AccountController::class, 'index'], ['auth' => true]],
    ['POST', '/account/email/resend', [AccountController::class, 'resendVerification'], ['auth' => true]],
    ['GET', '/account/notifications', [AccountController::class, 'notifications'], ['auth' => true]],
    ['POST', '/account/notifications/read', [AccountController::class, 'markNotificationRead'], ['auth' => true]],
    ['POST', '/account/notifications/preferences', [AccountController::class, 'saveNotificationPreferences'], ['auth' => true]],
    ['GET', '/account/security', [AccountController::class, 'security'], ['auth' => true]],
    ['POST', '/account/security/mfa/begin', [AccountController::class, 'mfaBegin'], ['auth' => true]],
    ['POST', '/account/security/mfa/confirm', [AccountController::class, 'mfaConfirm'], ['auth' => true]],
];

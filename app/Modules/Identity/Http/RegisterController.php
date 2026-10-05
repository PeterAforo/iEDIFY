<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class RegisterController extends Controller
{
    public function form(): Response
    {
        $this->enabled();
        return $this->render('identity/sign-up.twig', ['policy_version' => $this->app->config->string('POLICY_VERSION', '2026-10')]);
    }

    public function submit(): Response
    {
        $this->enabled();
        $this->throttle('auth.register', 5, 3600);
        // Honeypot fields: real users never see or fill these.
        if ($this->input('fax') !== '' || $this->input('department') !== '') {
            return $this->redirect('/sign-up/done');
        }
        $data = [
            'name' => $this->input('name'),
            'email' => $this->input('email'),
            'policy_version' => $this->app->config->string('POLICY_VERSION', '2026-10'),
            'age_attested' => $this->has('age_attested'),
            'terms_accepted' => $this->has('terms_accepted'),
        ];
        try {
            $userId = $this->app->identity()->register(
                $data['name'],
                $data['email'],
                (string) $this->request->request->get('password'),
                $data['policy_version'],
                $data['age_attested'] && $data['terms_accepted'],
            );
        } catch (InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->render('identity/sign-up.twig', ['data' => $data, 'policy_version' => $data['policy_version']], 422);
        }
        if ($userId === null) {
            $this->flash('error', 'An account already exists for this email address. Sign in or reset your password.');
            return $this->render('identity/sign-up.twig', ['data' => $data, 'policy_version' => $data['policy_version']], 422);
        }
        return $this->redirect('/sign-up/done');
    }

    public function done(): Response
    {
        $this->enabled();
        return $this->render('identity/sign-up-done.twig');
    }

    private function enabled(): void
    {
        if (!$this->app->config->boolean('SIGNUP_ENABLED')) {
            throw new HttpError(404, 'Registration is not currently open.');
        }
    }
}

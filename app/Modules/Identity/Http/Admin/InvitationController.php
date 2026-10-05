<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\SecretBox;
use IEdify\Modules\Identity\Services\InvitationService;
use Symfony\Component\HttpFoundation\Response;

final class InvitationController extends Controller
{
    public function index(): Response
    {
        return $this->render('admin/invitations/index.twig', [
            'invitations' => $this->service()->pending(),
            'roles' => $this->app->pdo()->query('SELECT id, name FROM roles ORDER BY privileged DESC, name')->fetchAll(),
        ]);
    }

    public function create(): Response
    {
        try {
            $this->service()->invite($this->requireActor(), $this->input('email'), (int) $this->request->request->get('role_id'));
            $this->flash('success', 'Invitation queued for delivery.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/invitations');
    }

    public function acceptForm(): Response
    {
        $invite = $this->service()->preview((string) $this->vars['token']);
        return $this->render('auth/invite.twig', ['invite' => $invite, 'token' => (string) $this->vars['token']]);
    }

    public function accept(): Response
    {
        $token = (string) $this->vars['token'];
        try {
            $this->service()->accept($token, $this->input('name'), (string) $this->request->request->get('password', ''));
            $this->flash('success', 'Your account is ready. Sign in to continue.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/invite/' . $token);
        }
        return $this->redirect('/sign-in');
    }

    private function service(): InvitationService
    {
        return new InvitationService($this->app->pdo(), new SecretBox($this->app->config->string('APP_KEY')));
    }
}

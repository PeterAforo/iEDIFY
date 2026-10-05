<?php

declare(strict_types=1);

namespace IEdify\Modules\Identity\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use IEdify\Modules\Identity\Services\DataRequestService;
use Symfony\Component\HttpFoundation\Response;

final class DataRequestController extends Controller
{
    public function index(): Response
    {
        return $this->render('admin/requests/index.twig', ['requests' => (new DataRequestService($this->app->pdo()))->pending()]);
    }

    public function decide(): Response
    {
        try {
            (new DataRequestService($this->app->pdo()))->decide($this->requireActor(), (int) $this->vars['id'], $this->input('decision'));
            $this->flash('success', 'Request updated.');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/requests');
    }
}

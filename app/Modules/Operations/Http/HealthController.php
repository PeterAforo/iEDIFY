<?php

declare(strict_types=1);

namespace IEdify\Modules\Operations\Http;

use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class HealthController extends Controller
{
    public function show(): Response
    {
        return new JsonResponse(['status' => 'ok']);
    }
}

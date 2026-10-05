<?php

declare(strict_types=1);

namespace IEdify\Modules\Operations\Http;

use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class HealthController extends Controller
{
    /** Liveness probe; database readiness is covered by the app:doctor cron job. */
    public function show(): Response
    {
        return new JsonResponse(['status' => 'ok']);
    }
}

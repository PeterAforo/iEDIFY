<?php

declare(strict_types=1);

namespace IEdify\Modules\Impact\Http;

use IEdify\Core\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

final class ImpactController extends Controller
{
    public function index(): Response
    {
        $period = $this->request->query->get('period');
        return $this->render('impact/index.twig', [
            'rows' => $this->app->impact()->publicSummary(is_string($period) && $period !== '' ? $period : null),
            'reports' => $this->app->impact()->publishedReports(),
            'period' => $period,
        ]);
    }

    public function report(): Response
    {
        return $this->render('impact/report.twig', ['report' => $this->app->impact()->report((int) $this->vars['id'])]);
    }
}

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
        $period = is_string($period) && $period !== '' ? $period : null;
        $impact = $this->app->impact();
        $rows = $impact->publicSummary($period);
        $threshold = $this->app->config->integer('IMPACT_SMALL_GROUP_THRESHOLD', 5, 0);
        $breakdown = $impact->publicBreakdown($period, $threshold);
        $cms = $this->cmsPage('/impact');
        return $this->render('impact/index.twig', [
            'rows' => $rows,
            'charts' => $rows !== [] ? $impact->chartConfig($rows) : [],
            'breakdown' => $breakdown,
            'threshold' => $threshold,
            'reports' => $impact->publishedReports(),
            'period' => $period,
            'page' => $cms['page'] ?? ['title' => 'Impact'],
            'sections' => $cms['sections'] ?? [],
            'info' => \IEdify\Modules\Web\Services\PageLayouts::build('/impact', $cms['sections'] ?? []),
            'media' => ($cms['media'] ?? []) + $this->mediaByIds([2, 9]),
        ]);
    }

    public function report(): Response
    {
        return $this->render('impact/report.twig', ['report' => $this->app->impact()->report((int) $this->vars['id'])]);
    }
}

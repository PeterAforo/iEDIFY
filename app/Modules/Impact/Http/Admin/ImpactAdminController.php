<?php

declare(strict_types=1);

namespace IEdify\Modules\Impact\Http\Admin;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class ImpactAdminController extends Controller
{
    public function index(): Response
    {
        $impact = $this->app->impact();
        return $this->render('admin/impact/index.twig', [
            'indicators' => $impact->indicators(),
            'results' => $impact->results(),
            'reports' => $impact->reports(),
        ]);
    }

    public function exportCsv(): Response
    {
        $csv = $this->app->impact()->exportResults($this->requireActor());
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="impact-results.csv"']);
    }

    public function createIndicator(): Response
    {
        return $this->attempt(fn () => $this->app->impact()->createIndicator($this->requireActor(), strtoupper((string) $this->request->request->get('code', '')), (string) $this->request->request->get('name', ''), (string) $this->request->request->get('definition', ''), (string) $this->request->request->get('unit', ''), (string) $this->request->request->get('source', 'operational'), (string) $this->request->request->get('calculation', ''), (string) $this->request->request->get('frequency', 'annual'), null, $this->request->request->get('validation')), 'Indicator created.');
    }

    public function setTarget(): Response
    {
        return $this->attempt(fn () => $this->app->impact()->setTarget($this->requireActor(), (int) $this->vars['id'], (string) $this->request->request->get('period', ''), (string) $this->request->request->get('target', ''), null), 'Target saved.');
    }

    public function submitResult(): Response
    {
        $evidence = $this->request->request->get('evidence_media_id');
        return $this->attempt(fn () => $this->app->impact()->submitResult($this->requireActor(), (int) $this->request->request->get('indicator_id', 0), (string) $this->request->request->get('period', ''), (string) $this->request->request->get('value', ''), null, null, $this->request->request->get('geography') ?: null, null, $this->request->request->get('source_note'), $evidence !== null && $evidence !== '' ? (int) $evidence : null), 'Result submitted for verification.');
    }

    public function verifyResult(): Response
    {
        return $this->resultAction(fn () => $this->app->impact()->verify($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('version', 0)), 'Result verified.');
    }

    public function publishResult(): Response
    {
        return $this->resultAction(fn () => $this->app->impact()->publish($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('version', 0)), 'Result published.');
    }

    public function rejectResult(): Response
    {
        return $this->resultAction(fn () => $this->app->impact()->reject($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('version', 0)), 'Result rejected.');
    }

    public function createReport(): Response
    {
        return $this->attempt(fn () => $this->app->impact()->createReport($this->requireActor(), (string) $this->request->request->get('title', ''), (string) $this->request->request->get('period', ''), (string) $this->request->request->get('summary', ''), (string) $this->request->request->get('body', '')), 'Report drafted.');
    }

    public function approveReport(): Response
    {
        return $this->attempt(fn () => $this->app->impact()->approveReport($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('version', 0)), 'Report approved.');
    }

    public function publishReport(): Response
    {
        return $this->attempt(fn () => $this->app->impact()->publishReport($this->requireActor(), (int) $this->vars['id'], (int) $this->request->request->get('version', 0)), 'Report published.');
    }

    private function resultAction(callable $action, string $success): Response
    {
        return $this->attempt($action, $success);
    }

    private function attempt(callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash('success', $success);
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect('/admin/impact');
    }
}

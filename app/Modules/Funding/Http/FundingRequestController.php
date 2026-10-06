<?php

declare(strict_types=1);

namespace IEdify\Modules\Funding\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class FundingRequestController extends Controller
{
    public function round(): Response
    {
        $statement = $this->app->pdo()->prepare("SELECT * FROM funding_rounds WHERE id = ? AND status = 'open' AND opens_at <= UTC_TIMESTAMP(6) AND closes_at >= UTC_TIMESTAMP(6)");
        $statement->execute([(int) $this->vars['id']]);
        $round = $statement->fetch();
        if ($round === false) {
            throw new HttpError(404, 'This funding round is not open.');
        }
        $fields = $round['form_schema'] !== null ? json_decode((string) $round['form_schema'], true, 512, JSON_THROW_ON_ERROR) : [];
        return $this->render('funding/round.twig', ['round' => $round, 'actor' => $this->actor(), 'fields' => $fields]);
    }

    public function mine(): Response
    {
        return $this->render('account/funding.twig', ['requests' => $this->app->funding()->requestsForActor($this->requireActor())]);
    }

    public function submit(): Response
    {
        $roundId = (int) $this->vars['id'];
        try {
            $startupId = $this->request->request->get('startup_id');
            $answers = (array) $this->request->request->all('answers');
            $schema = $this->app->pdo()->prepare('SELECT form_schema FROM funding_rounds WHERE id = ?');
            $schema->execute([$roundId]);
            $fields = ($raw = $schema->fetchColumn()) !== false && $raw !== null ? json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR) : [];
            $store = new \IEdify\Modules\Programs\Services\DocumentStore($this->app->pdo(), $this->app->config->path('CONTENT_STORAGE', $this->app->root . '/storage/private/content'));
            $uploaded = $this->request->files->get('answers', []);
            foreach ($fields as $field) {
                if ($field['type'] === 'document' && isset($uploaded[$field['key']])) {
                    $answers[$field['key']] = (string) $store->store($uploaded[$field['key']]);
                }
            }
            $budgetMediaId = null;
            $budgetFile = $this->request->files->get('budget_document');
            if ($budgetFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $budgetMediaId = $store->store($budgetFile);
            }
            $this->app->funding()->submitRequest(
                $this->requireActor(),
                $roundId,
                (string) $this->request->request->get('title', ''),
                (string) $this->request->request->get('amount', ''),
                $this->request->request->get('budget_summary'),
                $startupId !== null && $startupId !== '' ? (int) $startupId : null,
                $answers !== [] ? $answers : null,
                $budgetMediaId,
            );
            $this->flash('success', 'Your funding request was submitted.');
            return $this->redirect('/account/funding');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/funding/' . $roundId);
        }
    }
}

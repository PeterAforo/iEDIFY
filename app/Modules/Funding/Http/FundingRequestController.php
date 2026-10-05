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
        return $this->render('funding/round.twig', ['round' => $round, 'actor' => $this->actor()]);
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
            $this->app->funding()->submitRequest(
                $this->requireActor(),
                $roundId,
                (string) $this->request->request->get('title', ''),
                (string) $this->request->request->get('amount', ''),
                $this->request->request->get('budget_summary'),
                $startupId !== null && $startupId !== '' ? (int) $startupId : null,
            );
            $this->flash('success', 'Your funding request was submitted.');
            return $this->redirect('/account/funding');
        } catch (HttpError|\InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
            return $this->redirect('/funding/' . $roundId);
        }
    }
}

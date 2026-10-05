<?php

declare(strict_types=1);

namespace IEdify\Modules\Engagement\Http;

use IEdify\Core\Http\Controller;
use IEdify\Modules\Engagement\Services\NewsletterService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class NewsletterController extends Controller
{
    public function subscribe(): Response
    {
        $this->throttle('newsletter.subscribe', 10, 3600);
        try {
            (new NewsletterService($this->app->pdo(), $this->app->secrets()))->subscribe($this->input('email'), $this->actor()?->id);
            $this->flash('success', 'Check your inbox to confirm the subscription.');
        } catch (InvalidArgumentException $error) {
            $this->flash('error', $error->getMessage());
        }
        return $this->redirect($this->back());
    }

    public function confirm(): Response
    {
        $confirmed = (new NewsletterService($this->app->pdo(), $this->app->secrets()))->confirm((string) $this->vars['token']);
        return $this->render('engagement/newsletter-result.twig', [
            'heading' => $confirmed ? 'Subscription confirmed' : 'Link not valid',
            'message' => $confirmed ? 'You are subscribed to iEDIFY Africa updates. Every email includes an unsubscribe link.' : 'This confirmation link is invalid, expired or already used.',
        ], $confirmed ? 200 : 422);
    }

    public function unsubscribeForm(): Response
    {
        return $this->render('engagement/newsletter-unsubscribe.twig', ['token' => $this->vars['token']]);
    }

    public function unsubscribe(): Response
    {
        $done = (new NewsletterService($this->app->pdo(), $this->app->secrets()))->unsubscribe((string) $this->vars['token']);
        return $this->render('engagement/newsletter-result.twig', [
            'heading' => $done ? 'Unsubscribed' : 'Link not valid',
            'message' => $done ? 'You have been unsubscribed. No further newsletters will be sent.' : 'This unsubscribe link is invalid, expired or already used.',
        ], $done ? 200 : 422);
    }

    private function back(): string
    {
        $referer = $this->request->headers->get('referer', '');
        $path = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;
        return is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') && $this->request->getHost() === parse_url((string) $referer, PHP_URL_HOST) ? $path : '/';
    }
}

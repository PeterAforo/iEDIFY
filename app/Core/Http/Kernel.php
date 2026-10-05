<?php

declare(strict_types=1);

namespace IEdify\Core\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use IEdify\Core\Config;
use IEdify\Core\Security\Csrf;
use IEdify\Core\Security\SecurityHeaders;
use IEdify\Core\View\View;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

use function FastRoute\simpleDispatcher;

final readonly class Kernel
{
    public function __construct(private Config $config, private View $view, private SessionInterface $session, private LoggerInterface $logger)
    {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        try {
            if ($request->getHost() !== parse_url($this->config->string('APP_URL'), PHP_URL_HOST)) {
                throw new HttpError(400, 'The request host is not allowed.');
            }
            $dispatcher = simpleDispatcher(static function (RouteCollector $routes): void {
                $register = require dirname(__DIR__, 2) . '/Modules/Operations/routes.php';
                $register($routes);
            });
            $match = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());
            if ($match[0] === Dispatcher::NOT_FOUND) {
                throw new HttpError(404, 'This page is not available.');
            }
            if ($match[0] === Dispatcher::METHOD_NOT_ALLOWED) {
                throw new HttpError(405, 'This action is not available for this address.', ['Allow' => implode(', ', $match[1])]);
            }
            if (!$request->isMethodSafe()) {
                $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf');
                if (!(new Csrf($this->session))->valid(is_string($token) ? $token : null)) {
                    throw new HttpError(419, 'Your form session expired. Reload the page and try again.');
                }
            }
            $response = match ($match[1]) {
                'operations.health' => new JsonResponse(['status' => 'ok']),
                'operations.setup' => $this->config->environment() === 'production'
                    ? throw new HttpError(503, 'The website is temporarily unavailable.')
                    : $this->view->render('public/setup.twig'),
                default => throw new HttpError(404, 'This page is not available.'),
            };
        } catch (HttpError $error) {
            $response = $this->error($error->status, $error->getMessage(), $requestId);
            $response->headers->add($error->headers);
        } catch (SuspiciousOperationException) {
            $response = $this->error(400, 'The request could not be accepted.', $requestId);
        } catch (Throwable $error) {
            $this->logger->error('Request failed', ['request_id' => $requestId, 'exception_class' => $error::class]);
            $response = $this->error(500, 'The request could not be completed. Please try again later.', $requestId);
        }
        if ($this->config->environment() !== 'production' || $response->getStatusCode() >= 400) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        return (new SecurityHeaders())->apply($response, $request, $requestId);
    }

    private function error(int $status, string $message, string $requestId): Response
    {
        return $this->view->render('errors/error.twig', [
            'status' => $status,
            'heading' => match ($status) { 404 => 'Page not found', 403 => 'Access denied', 503 => 'Temporarily unavailable', default => 'Unable to complete this request' },
            'message' => $message,
            'request_id' => $requestId,
        ], $status);
    }
}

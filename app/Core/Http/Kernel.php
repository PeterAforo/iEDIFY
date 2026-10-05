<?php

declare(strict_types=1);

namespace IEdify\Core\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use IEdify\Core\Application;
use IEdify\Core\Security\Csrf;
use IEdify\Core\Security\SecurityHeaders;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function FastRoute\simpleDispatcher;

final readonly class Kernel
{
    private const MODULES = ['Operations', 'Identity', 'Engagement', 'CMS', 'Web'];

    public function __construct(private Application $app)
    {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        try {
            if ($request->getHost() !== parse_url($this->app->config->string('APP_URL'), PHP_URL_HOST)) {
                throw new HttpError(400, 'The request host is not allowed.');
            }
            $this->app->session->start();
            $definitions = [];
            $dispatcher = simpleDispatcher(function (RouteCollector $routes) use (&$definitions): void {
                foreach (self::MODULES as $module) {
                    $file = $this->app->root . '/app/Modules/' . $module . '/routes.php';
                    if (!is_file($file)) {
                        continue;
                    }
                    foreach (require $file as $definition) {
                        $definitions[] = $definition;
                        $routes->addRoute($definition[0], $definition[1], array_key_last($definitions));
                    }
                }
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
                if (!(new Csrf($this->app->session))->valid(is_string($token) ? $token : null)) {
                    throw new HttpError(419, 'Your form session expired. Reload the page and try again.');
                }
            }
            $definition = $definitions[$match[1]];
            $options = $definition[3] ?? [];
            [$class, $method] = $definition[2];
            $response = $this->guard($request, $options) ?? (new $class($this->app, $request, $match[2]))->$method();
        } catch (HttpError $error) {
            if ($error->status === 401) {
                $response = new RedirectResponse('/sign-in');
            } else {
                $response = $this->error($error->status, $error->getMessage(), $requestId);
                $response->headers->add($error->headers);
            }
        } catch (SuspiciousOperationException) {
            $response = $this->error(400, 'The request could not be accepted.', $requestId);
        } catch (Throwable $error) {
            $this->app->logger->error('Request failed', ['request_id' => $requestId, 'exception_class' => $error::class]);
            $response = $this->error(500, 'The request could not be completed. Please try again later.', $requestId);
        }
        try {
            $this->app->session->save();
        } catch (Throwable $error) {
            $this->app->logger->warning('Session could not be saved', ['request_id' => $requestId, 'exception_class' => $error::class]);
        }
        if ($this->app->config->environment() !== 'production' || $response->getStatusCode() >= 400) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        return (new SecurityHeaders())->apply($response, $request, $requestId);
    }

    private function guard(Request $request, array $options): ?Response
    {
        $actor = $this->app->actor();
        if (($options['guest'] ?? false) && $actor !== null) {
            return new RedirectResponse('/account');
        }
        $requiresIdentity = ($options['auth'] ?? false) || isset($options['permission']) || ($options['privileged'] ?? false);
        if ($requiresIdentity && $actor === null) {
            $this->app->session->set(Application::SESSION_INTENDED, $request->getPathInfo());
            return new RedirectResponse('/sign-in');
        }
        if (($options['privileged'] ?? false) && $actor !== null && !($actor->privileged && $actor->mfaComplete)) {
            throw new HttpError(403, 'Administrative access requires a privileged account with multi-factor authentication.');
        }
        if (isset($options['permission']) && !$this->app->policy()->allows($actor, $options['permission'])) {
            if ($actor !== null && !$actor->verified) {
                throw new HttpError(403, 'Verify your email address before using this feature.');
            }
            if ($actor !== null && $actor->privileged && !$actor->mfaComplete) {
                return new RedirectResponse('/account/security');
            }
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
        return null;
    }

    private function error(int $status, string $message, string $requestId): Response
    {
        return $this->app->view->render('errors/error.twig', [
            'status' => $status,
            'heading' => match ($status) { 404 => 'Page not found', 403 => 'Access denied', 419 => 'Session expired', 429 => 'Too many attempts', 503 => 'Temporarily unavailable', default => 'Unable to complete this request' },
            'message' => $message,
            'request_id' => $requestId,
        ], $status);
    }
}

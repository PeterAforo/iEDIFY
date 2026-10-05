<?php

declare(strict_types=1);

namespace IEdify\Modules\Learning\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class LearnController extends Controller
{
    public function index(): Response
    {
        return $this->render('learning/index.twig', [
            'courses' => $this->app->learning()->enrolledCourses($this->requireActor()),
        ]);
    }

    public function show(): Response
    {
        $course = $this->app->learning()->courseDetail($this->requireActor(), (int) $this->vars['id']);
        return $this->render('learning/course.twig', ['course' => $course]);
    }

    public function complete(): Response
    {
        try {
            $this->app->learning()->completeLesson($this->requireActor(), (int) $this->vars['id']);
            $this->flash('success', 'Lesson marked complete.');
        } catch (HttpError $error) {
            $this->flash('error', $error->getMessage());
        }
        $referer = $this->request->headers->get('Referer');
        $path = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;
        return $this->redirect(is_string($path) && str_starts_with($path, '/learn/') ? $path : '/learn');
    }
}

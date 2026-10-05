<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Http;

use IEdify\Core\Http\Controller;
use IEdify\Core\Http\HttpError;
use Symfony\Component\HttpFoundation\Response;

final class PublicProgramController extends Controller
{
    public function index(): Response
    {
        return $this->render('public/programs.twig', [
            'programs' => $this->app->programs()->catalogue(),
        ]);
    }

    public function show(): Response
    {
        $statement = $this->app->pdo()->prepare("SELECT * FROM programs WHERE slug = ? AND status = 'open'");
        $statement->execute([(string) $this->vars['slug']]);
        $program = $statement->fetch();
        if ($program === false) {
            throw new HttpError(404, 'This program is not available.');
        }
        $intakes = $this->app->pdo()->prepare("SELECT id, name, opens_at, closes_at, seats FROM intakes WHERE program_id = ? AND status != 'draft' ORDER BY opens_at");
        $intakes->execute([$program['id']]);
        return $this->render('public/program-detail.twig', [
            'program' => $program,
            'intakes' => $intakes->fetchAll(),
        ]);
    }
}

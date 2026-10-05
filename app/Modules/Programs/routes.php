<?php

declare(strict_types=1);

use IEdify\Modules\Programs\Http\Admin\ApplicationAdminController;
use IEdify\Modules\Programs\Http\Admin\ProgramAdminController;
use IEdify\Modules\Programs\Http\ApplyController;
use IEdify\Modules\Programs\Http\PublicProgramController;

return [
    ['GET', '/programs', [PublicProgramController::class, 'index']],
    ['GET', '/programs/{slug:[a-z0-9-]+}', [PublicProgramController::class, 'show']],
    ['GET', '/account/applications', [ApplyController::class, 'index'], ['auth' => true]],
    ['GET', '/apply/{intakeId:\d+}', [ApplyController::class, 'form'], ['permission' => 'application.submit']],
    ['POST', '/apply/{intakeId:\d+}', [ApplyController::class, 'save'], ['permission' => 'application.submit']],
    ['POST', '/applications/{id:\d+}/submit', [ApplyController::class, 'submit'], ['permission' => 'application.submit']],
    ['POST', '/applications/{id:\d+}/withdraw', [ApplyController::class, 'withdraw'], ['permission' => 'application.submit']],
    ['POST', '/applications/{id:\d+}/respond', [ApplyController::class, 'respond'], ['permission' => 'application.submit']],
    ['POST', '/applications/{id:\d+}/documents/{key:[a-z0-9_]+}', [ApplyController::class, 'uploadDocument'], ['permission' => 'application.submit']],
    ['GET', '/admin/programs', [ProgramAdminController::class, 'index'], ['permission' => 'program.manage']],
    ['POST', '/admin/programs', [ProgramAdminController::class, 'create'], ['permission' => 'program.manage']],
    ['GET', '/admin/programs/{id:\d+}', [ProgramAdminController::class, 'show'], ['permission' => 'program.manage']],
    ['POST', '/admin/programs/{id:\d+}/status', [ProgramAdminController::class, 'setStatus'], ['permission' => 'program.manage']],
    ['POST', '/admin/programs/{id:\d+}/intakes', [ProgramAdminController::class, 'createIntake'], ['permission' => 'program.manage']],
    ['POST', '/admin/intakes/{id:\d+}/status', [ProgramAdminController::class, 'setIntakeStatus'], ['permission' => 'program.manage']],
    ['POST', '/admin/intakes/{id:\d+}/forms', [ProgramAdminController::class, 'createForm'], ['permission' => 'program.manage']],
    ['POST', '/admin/programs/{id:\d+}/cohorts', [ProgramAdminController::class, 'createCohort'], ['permission' => 'cohort.manage']],
    ['GET', '/admin/applications', [ApplicationAdminController::class, 'index'], ['permission' => 'application.review']],
    ['GET', '/admin/applications/export.csv', [ApplicationAdminController::class, 'exportCsv'], ['permission' => 'application.review']],
    ['GET', '/admin/applications/{id:\d+}', [ApplicationAdminController::class, 'show'], ['permission' => 'application.review']],
    ['POST', '/admin/applications/{id:\d+}/transition', [ApplicationAdminController::class, 'transition'], ['privileged' => true]],
    ['POST', '/admin/applications/{id:\d+}/assign', [ApplicationAdminController::class, 'assign'], ['permission' => 'program.manage']],
    ['POST', '/admin/applications/{id:\d+}/review', [ApplicationAdminController::class, 'review'], ['permission' => 'application.review']],
    ['POST', '/admin/applications/{id:\d+}/enroll', [ApplicationAdminController::class, 'enroll'], ['permission' => 'cohort.manage']],
];

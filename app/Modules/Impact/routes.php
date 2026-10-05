<?php

declare(strict_types=1);

use IEdify\Modules\Impact\Http\Admin\ImpactAdminController;
use IEdify\Modules\Impact\Http\ImpactController;

return [
    ['GET', '/impact', [ImpactController::class, 'index']],
    ['GET', '/impact/reports/{id:\d+}', [ImpactController::class, 'report']],
    ['GET', '/admin/impact', [ImpactAdminController::class, 'index'], ['permission' => 'impact.edit']],
    ['GET', '/admin/impact/export.csv', [ImpactAdminController::class, 'exportCsv'], ['permission' => 'export.run']],
    ['POST', '/admin/impact/indicators', [ImpactAdminController::class, 'createIndicator'], ['permission' => 'impact.edit']],
    ['POST', '/admin/impact/indicators/{id:\d+}/targets', [ImpactAdminController::class, 'setTarget'], ['permission' => 'impact.edit']],
    ['POST', '/admin/impact/results', [ImpactAdminController::class, 'submitResult'], ['permission' => 'impact.submit']],
    ['POST', '/admin/impact/results/{id:\d+}/verify', [ImpactAdminController::class, 'verifyResult'], ['permission' => 'impact.verify']],
    ['POST', '/admin/impact/results/{id:\d+}/publish', [ImpactAdminController::class, 'publishResult'], ['permission' => 'impact.publish']],
    ['POST', '/admin/impact/results/{id:\d+}/reject', [ImpactAdminController::class, 'rejectResult'], ['permission' => 'impact.verify']],
    ['POST', '/admin/impact/reports', [ImpactAdminController::class, 'createReport'], ['permission' => 'impact.edit']],
    ['POST', '/admin/impact/reports/{id:\d+}/approve', [ImpactAdminController::class, 'approveReport'], ['permission' => 'impact.publish']],
    ['POST', '/admin/impact/reports/{id:\d+}/publish', [ImpactAdminController::class, 'publishReport'], ['permission' => 'impact.publish']],
];

<?php

declare(strict_types=1);

use IEdify\Modules\Operations\Http\Admin\OutboxController;
use IEdify\Modules\Operations\Http\HealthController;

return [
    ['GET', '/health', [HealthController::class, 'show']],
    ['GET', '/admin/outbox', [OutboxController::class, 'index'], ['permission' => 'operations.manage']],
    ['POST', '/admin/outbox/{id:\d+}/retry', [OutboxController::class, 'retry'], ['permission' => 'operations.manage']],
];

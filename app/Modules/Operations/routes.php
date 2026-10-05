<?php

declare(strict_types=1);

use IEdify\Modules\Operations\Http\HealthController;

return [
    ['GET', '/health', [HealthController::class, 'show']],
];

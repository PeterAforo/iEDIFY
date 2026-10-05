<?php

declare(strict_types=1);

use IEdify\Modules\Web\Http\MediaController;
use IEdify\Modules\Web\Http\PageController;

return [
    ['GET', '/', [PageController::class, 'home']],
    ['GET', '/media/{id:\d+}', [MediaController::class, 'serve']],
    ['GET', '/{slug:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*}', [PageController::class, 'show']],
];
